<?php
namespace GeoMetodoSEO\EEAT;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\Config\ConfigManager;

class EEATEngine {

    public function apply($post_id) {
        $author_name       = ConfigManager::get('author_name',            'Alison Jean');
        $author_bio        = ConfigManager::get('author_bio',             'Especialista em SEO e GEO.');
        $author_photo      = ConfigManager::get('author_photo',           '');
        $author_spec       = ConfigManager::get('author_specialty',       '');
        $author_exp        = ConfigManager::get('author_experience',      '');
        $author_certs      = ConfigManager::get('author_certifications',  '');
        $editorial_page    = ConfigManager::get('author_editorial_page',  '');
        $subdomains_raw    = ConfigManager::get('author_subdomains',      '');
        $categories_raw    = ConfigManager::get('author_site_categories', '');
        $social_twitter    = ConfigManager::get('social_twitter',         '');
        $social_linkedin   = ConfigManager::get('social_linkedin',        '');
        $social_instagram  = ConfigManager::get('social_instagram',       '');
        $social_facebook   = ConfigManager::get('social_facebook',        '');

        update_post_meta($post_id, 'geo_author_name', $author_name);
        update_post_meta($post_id, 'geo_author_bio',  $author_bio);

        $this->save_article_schema($post_id, $author_name, $author_spec, $author_photo, $editorial_page, $social_twitter, $social_linkedin);
        $this->extract_and_save_faq($post_id);

        // Fix 3: skip author box if name is not configured
        if (!empty($author_name)) {
            $this->append_author_box(
                $post_id,
                $author_name, $author_bio, $author_photo,
                $author_spec, $author_exp, $author_certs,
                $editorial_page, $subdomains_raw, $categories_raw,
                $social_twitter, $social_linkedin, $social_instagram, $social_facebook
            );
        }
    }

    /**
     * Constroi e salva o schema Article completo como post_meta.
     * Inclui: headline, description, url, datePublished, dateModified, author, publisher, image.
     */
    private function save_article_schema($post_id, $author_name, $author_spec, $author_photo, $editorial_page, $social_twitter, $social_linkedin) {
        $title        = get_the_title($post_id);
        $excerpt      = get_post_field('post_excerpt', $post_id);
        $permalink    = get_permalink($post_id);
        $date_pub     = get_post_field('post_date', $post_id);
        $date_mod     = get_post_field('post_modified', $post_id);
        $thumb_url    = get_the_post_thumbnail_url($post_id, 'large');
        $site_name    = get_bloginfo('name');
        $site_url     = get_site_url();

        $author_schema = [
            '@type' => 'Person',
            'name'  => $author_name,
        ];
        if ($author_spec) {
            $author_schema['jobTitle'] = $author_spec;
        }
        if ($author_photo) {
            $author_schema['image'] = $author_photo;
        }
        $same_as = array_values(array_filter([$editorial_page, $social_twitter, $social_linkedin]));
        if (!empty($same_as)) {
            $author_schema['sameAs'] = $same_as;
        }
        if (!empty($editorial_page)) {
            $author_schema['url'] = $editorial_page;
        }

        $word_count = str_word_count(strip_tags(get_post_field('post_content', $post_id)));
        $keyword    = get_post_meta($post_id, '_geo_keyword', true) ?: $title;
        $lang       = get_bloginfo('language') ?: 'pt-BR';

        $schema = [
            '@context'            => 'https://schema.org',
            '@type'               => 'Article',
            '@id'                 => $permalink . '#article',
            'mainEntityOfPage'    => ['@type' => 'WebPage', '@id' => $permalink],
            'headline'            => mb_substr($title, 0, 110),
            'name'                => $title,
            'description'         => mb_substr($excerpt ?: $title, 0, 160),
            'url'                 => $permalink,
            'datePublished'       => $date_pub ? date('c', strtotime($date_pub)) : '',
            'dateModified'        => $date_mod ? date('c', strtotime($date_mod)) : '',
            'author'              => $author_schema,
            'publisher'           => [
                '@type' => 'Organization',
                '@id'   => $site_url . '/#organization',
                'name'  => $site_name,
                'url'   => $site_url,
                'logo'  => ['@type' => 'ImageObject', 'url' => get_site_icon_url(64) ?: $site_url],
            ],
            'inLanguage'          => $lang,
            'wordCount'           => $word_count,
            'keywords'            => $keyword,
            'isAccessibleForFree' => true,
            'speakable'           => [
                '@type'       => 'SpeakableSpecification',
                'cssSelector' => ['.geo-quick-answer', 'h1.entry-title', '.entry-content p:first-of-type'],
            ],
        ];

        if ($thumb_url) {
            $schema['image'] = ['@type' => 'ImageObject', 'url' => $thumb_url, '@id' => $permalink . '#primaryimage'];
        }

        // Schema Person separado (para E-E-A-T e AEO) com campos completos
        $knowsabout  = get_option('geo_author_knowsabout', '');
        $awards      = get_option('geo_author_awards', '');
        $credentials = get_option('geo_author_credentials', '');

        $person_schema = [
            '@context' => 'https://schema.org',
            '@type'    => 'Person',
            '@id'      => $site_url . '/#author',
            'name'     => $author_name,
            'worksFor' => ['@type' => 'Organization', '@id' => $site_url . '/#organization'],
        ];
        if ($author_spec)     $person_schema['jobTitle'] = $author_spec;
        if ($author_photo)    $person_schema['image']    = ['@type' => 'ImageObject', 'url' => $author_photo, 'width' => 200, 'height' => 200];
        if ($editorial_page)  $person_schema['url']      = $editorial_page;
        $author_same_as = array_values(array_filter([$editorial_page, $social_twitter, $social_linkedin]));
        if (!empty($author_same_as)) $person_schema['sameAs'] = $author_same_as;
        // knowsAbout — expertise para GEO/LLM
        if (!empty($knowsabout)) {
            $person_schema['knowsAbout'] = array_map('trim', explode(',', $knowsabout));
        }
        // award — reconhecimentos para E-E-A-T
        if (!empty($awards)) {
            $person_schema['award'] = array_map('trim', explode(',', $awards));
        }
        // hasCredential — EducationalOccupationalCredential
        if (!empty($credentials)) {
            $creds = array_filter(array_map('trim', explode(',', $credentials)));
            $person_schema['hasCredential'] = array_map(function($c) {
                return ['@type' => 'EducationalOccupationalCredential', 'credentialCategory' => 'Certification', 'name' => $c];
            }, $creds);
        }
        update_post_meta($post_id, 'geo_person_schema', wp_json_encode($person_schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        update_post_meta($post_id, 'geo_article_schema', wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        // Manter retrocompatibilidade com a chave antiga
        update_post_meta($post_id, 'geo_schema', wp_json_encode([
            '@context' => 'https://schema.org',
            '@type'    => 'Article',
            'author'   => $author_schema,
            'publisher' => ['@type' => 'Organization', 'name' => $site_name],
        ]));
    }

    /**
     * Extrai pares pergunta/resposta da seção FAQ do post e salva o schema FAQPage.
     * Procura pela seção <h2>Perguntas Frequentes</h2> e coleta H3 + P dentro dela.
     */
    public function extract_and_save_faq($post_id) {
        // Primary: use raw faq_texto saved by ArticlePipeline (most reliable)
        $faq_raw = get_post_meta($post_id, '_geo_faq_raw', true);
        if (!empty($faq_raw)) {
            $pairs = $this->extract_faq_pairs($faq_raw);
            if (!empty($pairs)) {
                $this->save_faq_schema($post_id, $pairs);
                return;
            }
        }

        // Fallback: parse post_content for FAQ section
        $content = get_post_field('post_content', $post_id);
        if (!$content) {
            return;
        }

        $pairs = $this->extract_faq_pairs($content);

        if (empty($pairs)) {
            return;
        }

        $this->save_faq_schema($post_id, $pairs);
    }

    private function save_faq_schema($post_id, $pairs) {
        $entities = [];
        foreach ($pairs as $pair) {
            $entities[] = [
                '@type' => 'Question',
                'name'  => $pair['q'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => $pair['a'],
                ],
            ];
        }

        $schema = [
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => $entities,
        ];

        update_post_meta($post_id, 'geo_faq_schema', wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Extrai pares {q, a} da seção de FAQ do HTML do post.
     * Estratégia: localiza o bloco após <h2>Perguntas Frequentes</h2>,
     * depois extrai H3/H4 como perguntas e o texto seguinte como respostas.
     */
    private function extract_faq_pairs($content) {
        // 1. Isola a seção FAQ (entre o H2 de FAQ e o próximo H2 ou fim do conteúdo)
        $faq_section = '';
        if (preg_match(
            '/<h2[^>]*>\s*(?:Perguntas[^<]*|FAQ[^<]*)<\/h2>(.*?)(?=<h2[\s>]|<div\s[^>]*geo-author-box|$)/si',
            $content,
            $m
        )) {
            $faq_section = $m[1];
        }

        if (empty(trim($faq_section))) {
            return [];
        }

        // 2. Extrai cada H3/H4 e o conteúdo até o próximo H3/H4 como par Q/A
        $pairs = [];
        $segments = preg_split('/(?=<h[34][\s>])/i', $faq_section, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($segments as $seg) {
            if (!preg_match('/<h[34][^>]*>(.*?)<\/h[34]>(.*)/si', $seg, $part)) {
                continue;
            }
            $question = trim(strip_tags($part[1]));
            $answer   = trim(strip_tags($part[2]));

            // Limpa espaços e quebras de linha multiplos
            $answer = preg_replace('/\s+/', ' ', $answer);

            if (strlen($question) > 5 && strlen($answer) > 10) {
                $pairs[] = ['q' => $question, 'a' => $answer];
            }
        }

        // Fallback: se não encontrou H3, tenta strong + p (formato "P: ... R: ...")
        if (empty($pairs)) {
            preg_match_all('/<strong[^>]*>(.*?\?)<\/strong>\s*<p[^>]*>(.*?)<\/p>/si', $faq_section, $m2, PREG_SET_ORDER);
            foreach ($m2 as $match) {
                $question = trim(strip_tags($match[1]));
                $answer   = trim(strip_tags($match[2]));
                if ($question && $answer) {
                    $pairs[] = ['q' => $question, 'a' => $answer];
                }
            }
        }

        return array_slice($pairs, 0, 20); // max 20 Q&A no schema
    }

    private function append_author_box(
        $post_id,
        $name, $bio, $photo,
        $spec, $exp, $certs,
        $editorial_page, $subdomains_raw, $categories_raw,
        $twitter, $linkedin, $instagram, $facebook
    ) {
        $post = get_post($post_id);
        if (!$post) {
            return;
        }

        // 1.0.0: evita caixas duplicadas e garante que o ecossistema do autor fique no rodapé real do artigo.
        $clean_content = preg_replace([
            '/<div\s+class="[^"]*geo-author-box[^"]*"[^>]*>.*?<div\s+style="clear:both;"><\/div>\s*<\/div>\s*/isu',
            '/<div\s+class="[^"]*geo-author-box[^"]*"[^>]*>.*?<\/div>\s*$/isu',
        ], '', $post->post_content);
        if ($clean_content === null) {
            $clean_content = $post->post_content;
        }

        $post_date = get_the_date('d/m/Y', $post_id);

        $photo_html = '';
        if (!empty($photo)) {
            $photo_html = '<img src="' . esc_url($photo) . '" alt="' . esc_attr($name) . '"'
                        . ' style="width:80px;height:80px;border-radius:50%;object-fit:cover;margin-right:16px;float:left;">';
        }

        $meta_parts = [];
        if (!empty($spec)) {
            $meta_parts[] = esc_html($spec);
        }
        if (!empty($exp)) {
            $meta_parts[] = esc_html($exp) . ' de experiencia';
        }
        $meta_parts[] = 'Publicado em ' . esc_html($post_date);

        $meta_html = '<p style="margin:2px 0 6px;font-size:13px;color:inherit;opacity:.78;">'
                   . implode(' · ', $meta_parts)
                   . '</p>';

        $certs_html = '';
        if (!empty($certs)) {
            $certs_html = '<p style="margin:4px 0 0;font-size:12px;color:#888;">🏆 '
                        . esc_html($certs) . '</p>';
        }
        // knowsAbout visível (expertise)
        $knowsabout_val = get_option('geo_author_knowsabout', '');
        $knowsabout_html = '';
        if (!empty($knowsabout_val)) {
            $areas = array_slice(array_map('trim', explode(',', $knowsabout_val)), 0, 5);
            $tags  = implode('', array_map(function($a) {
                return '<span style="display:inline-block;background:#f0f8ff;border:1px solid #c5d5f5;border-radius:3px;padding:2px 7px;font-size:11px;color:#0073aa;margin:2px 2px 0 0;">'
                     . esc_html(trim($a)) . '</span>';
            }, $areas));
            $knowsabout_html = '<p style="margin:6px 0 0;font-size:12px;color:inherit;opacity:.82;">🎯 Expertise: ' . $tags . '</p>';
        }
        // Credenciais visíveis
        $credentials_val = get_option('geo_author_credentials', '');
        $credentials_html = '';
        if (!empty($credentials_val)) {
            $cred_list = array_filter(array_map('trim', explode(',', $credentials_val)));
            $cred_items = implode('', array_map(function($c) {
                return '<span style="display:inline-block;background:#f0fff0;border:1px solid #86efac;border-radius:3px;padding:2px 7px;font-size:11px;color:#16a34a;margin:2px 2px 0 0;">✓ '
                     . esc_html(trim($c)) . '</span>';
            }, $cred_list));
            $credentials_html = '<p style="margin:4px 0 0;font-size:12px;">📋 ' . $cred_items . '</p>';
        }

        $editorial_html = '';
        if (!empty($editorial_page)) {
            $editorial_html = '<p style="margin:6px 0 0;font-size:12px;">'
                            . '<a href="' . esc_url($editorial_page) . '" target="_blank" rel="noopener" style="color:inherit;opacity:.82;text-decoration:none;">'
                            . '📋 Pagina Editorial</a></p>';
        }

        $subdomains_html = '';
        if (!empty($subdomains_raw)) {
            $links = $this->build_url_links($subdomains_raw, '#0073aa');
            if (!empty($links)) {
                $subdomains_html = '<p style="margin:6px 0 0;font-size:12px;color:inherit;opacity:.82;">🌐 '
                                 . implode(' · ', $links) . '</p>';
            }
        }

        $categories_html = '';
        if (!empty($categories_raw)) {
            $links = $this->build_url_links($categories_raw, '#555');
            if (!empty($links)) {
                $categories_html = '<p style="margin:6px 0 0;font-size:12px;color:inherit;opacity:.82;">🗂️ '
                                 . implode(' · ', $links) . '</p>';
            }
        }

        $social_links = [];
        if (!empty($twitter)) {
            $social_links[] = '<a href="' . esc_url($twitter) . '" target="_blank" rel="noopener" style="color:#1d9bf0;text-decoration:none;font-size:13px;">Twitter/X</a>';
        }
        if (!empty($linkedin)) {
            $social_links[] = '<a href="' . esc_url($linkedin) . '" target="_blank" rel="noopener" style="color:#0a66c2;text-decoration:none;font-size:13px;">LinkedIn</a>';
        }
        if (!empty($instagram)) {
            $social_links[] = '<a href="' . esc_url($instagram) . '" target="_blank" rel="noopener" style="color:#e1306c;text-decoration:none;font-size:13px;">Instagram</a>';
        }
        if (!empty($facebook)) {
            $social_links[] = '<a href="' . esc_url($facebook) . '" target="_blank" rel="noopener" style="color:#1877f2;text-decoration:none;font-size:13px;">Facebook</a>';
        }

        $social_html = '';
        if (!empty($social_links)) {
            $social_html = '<p style="margin:8px 0 0;font-size:13px;">'
                         . implode(' · ', $social_links) . '</p>';
        }

        $box = '<div class="geo-author-box" style="margin-top:40px;padding:20px 24px;background:transparent;border:1px solid rgba(148,163,184,.35);border-radius:8px;overflow:hidden;">'
             . $photo_html
             . '<div>'
             . '<strong style="font-size:16px;">' . esc_html($name) . '</strong>'
             . $meta_html
             . '<p style="margin:4px 0;font-size:14px;color:inherit;opacity:.86;">' . esc_html($bio) . '</p>'
             . $certs_html
             . $credentials_html
             . $knowsabout_html
             . $editorial_html
             . $subdomains_html
             . $categories_html
             . $social_html
             . '</div>'
             . '<div style="clear:both;"></div>'
             . '</div>';

        \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post([
            'ID'           => $post_id,
            'post_content' => trim($clean_content) . "\n\n" . $box,
        ]);
    }

    /**
     * Converte textarea (URLs uma por linha) em array de links HTML.
     */
    private function build_url_links($raw, $color = '#0073aa') {
        $lines = array_filter(array_map('trim', explode("\n", $raw)));
        $links = [];
        foreach ($lines as $line) {
            $path  = rtrim(parse_url($line, PHP_URL_PATH) ?? '', '/');
            $label = $path ? basename($path) : parse_url($line, PHP_URL_HOST);
            $label = str_replace(['-', '_'], ' ', $label);
            $label = ucwords($label);

            $links[] = '<a href="' . esc_url($line) . '" target="_blank" rel="noopener"'
                     . ' style="color:' . esc_attr($color) . ';text-decoration:none;">'
                     . esc_html($label) . '</a>';
        }
        return $links;
    }

    /**
     * Injeta Author Box reforçado com credenciais E-E-A-T no fim do conteúdo.
     *
     * Lê os user_meta criados pelo AuthorProfileFields:
     *  - geo_expertise_area
     *  - geo_years_experience
     *  - geo_credentials
     *  - geo_linkedin
     *
     * Google March 2026 expandiu E-E-A-T pra todo conteúdo. Este Author Box
     * reforçado torna autoria visível pro algoritmo e pro leitor.
     *
     * @since 1.0.0
     * @param int $post_id
     * @return bool true se box foi injetado, false se setting OFF / já tem box / etc
     */
    public function inject_reinforced_author_box(int $post_id): bool {
        if (!$post_id) return false;
        $enabled = (string) get_option('geo_author_box_reinforced', '1') === '1';
        if (!$enabled) return false;

        $post = get_post($post_id);
        if (!$post) return false;

        // Skip se já tem o box (evita duplicação em re-runs)
        if (stripos($post->post_content, 'geo-author-box-reinforced') !== false) return false;

        $author_id = (int) $post->post_author;
        $author    = get_user_by('id', $author_id);
        if (!$author) return false;

        $name        = (string) $author->display_name;
        $bio         = (string) get_user_meta($author_id, 'description', true);
        $avatar      = get_avatar_url($author_id, ['size' => 96]);
        $author_url  = get_author_posts_url($author_id);

        // Credenciais E-E-A-T (do AuthorProfileFields)
        $credentials       = (string) get_user_meta($author_id, 'geo_credentials', true);
        $linkedin          = (string) get_user_meta($author_id, 'geo_linkedin', true);
        $years_experience  = (string) get_user_meta($author_id, 'geo_years_experience', true);
        $area_expertise    = (string) get_user_meta($author_id, 'geo_expertise_area', true);

        if ($bio === '') {
            $bio = sprintf('Conteúdo escrito e revisado por %s, parte da equipe editorial.', $name);
        }

        // Monta o HTML
        $box  = '<div class="geo-author-box-reinforced" style="margin:40px 0 20px;padding:24px;background:transparent;border-left:4px solid #0284c7;border-radius:6px;display:flex;gap:20px;align-items:flex-start;">';

        if ($avatar) {
            $box .= '<img src="' . esc_url($avatar) . '" alt="' . esc_attr($name) . '" style="width:80px;height:80px;border-radius:50%;flex-shrink:0;" />';
        }

        $box .= '<div style="flex:1;min-width:0;">';
        $box .= '<div style="font-size:13px;color:#6b7280;text-transform:uppercase;font-weight:600;margin-bottom:4px;letter-spacing:0.5px;">✍️ Sobre o autor</div>';
        $box .= '<h3 style="margin:0 0 8px;font-size:18px;color:#111;"><a href="' . esc_url($author_url) . '" style="text-decoration:none;color:inherit;">' . esc_html($name) . '</a></h3>';

        // Linha de credenciais
        $cred_parts = [];
        if ($area_expertise !== '')   $cred_parts[] = esc_html($area_expertise);
        if ($years_experience !== '') $cred_parts[] = esc_html($years_experience) . ' anos de experiência';
        if ($credentials !== '')      $cred_parts[] = esc_html($credentials);

        if (!empty($cred_parts)) {
            $box .= '<div style="font-size:13px;color:#0284c7;margin-bottom:8px;font-weight:500;">' . implode(' • ', $cred_parts) . '</div>';
        }

        $box .= '<p style="margin:0 0 12px;font-size:14px;color:#374151;line-height:1.6;">' . esc_html($bio) . '</p>';

        // Links
        $links = [];
        if ($linkedin !== '') {
            $links[] = '<a href="' . esc_url($linkedin) . '" target="_blank" rel="noopener" style="color:#0284c7;text-decoration:none;">LinkedIn</a>';
        }
        $links[] = '<a href="' . esc_url($author_url) . '" style="color:#0284c7;text-decoration:none;">Mais artigos</a>';

        $box .= '<div style="font-size:13px;">' . implode(' &middot; ', $links) . '</div>';
        $box .= '</div></div>';

        $new_content = $post->post_content . "\n\n" . $box;
        \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post(['ID' => $post_id, 'post_content' => $new_content]);

        return true;
    }
}
