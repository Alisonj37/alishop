<?php
declare(strict_types=1);

namespace GeoMetodoSEO\Autopilot\MediaOpportunities;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\Autopilot\Shared\AutopilotLogger;

/**
 * SaraMediaCollector — Coleta queries de jornalistas de fontes públicas e gratuitas.
 *
 * Estratégia: usa apenas fontes que NÃO exigem login/API key/IMAP.
 * Tudo é RSS público ou hashtag pública via Nitter (proxy gratuito do Twitter).
 *
 * Compliance: não burla nenhum ToS, não cria conta automaticamente, não envia pitches.
 * O plugin só LÊ feeds públicos e mostra ao usuário oportunidades relevantes.
 *
 * @since 1.0.0
 */
class SaraMediaCollector {

    /** Tabela onde oportunidades coletadas são armazenadas */
    private const TABLE_SUFFIX = 'sara_media_opportunities';

    /** Cache para evitar refetch (8 horas) */
    private const CACHE_TTL = 8 * HOUR_IN_SECONDS;

    /**
     * Fontes públicas. Cada uma tem URL RSS + idioma + tipo.
     * Usuário pode habilitar/desabilitar individualmente em wp_options.
     *
     * Para cada idioma há instâncias diferentes (Nitter para Twitter/X tem várias).
     * Se uma instância falhar, tenta a próxima.
     */
    public static function get_sources(): array {
        return [
            // ── Reddit (público, RSS oficial) ────────────────────────────────
            'reddit_journorequests' => [
                'name'    => 'Reddit r/journorequests',
                'urls'    => ['https://www.reddit.com/r/journorequests/new/.rss'],
                'lang'    => 'en',
                'type'    => 'reddit',
                'enabled' => true,
            ],
            'reddit_haro' => [
                'name'    => 'Reddit r/HARO',
                'urls'    => ['https://www.reddit.com/r/HARO/new/.rss'],
                'lang'    => 'en',
                'type'    => 'reddit',
                'enabled' => true,
            ],
            'reddit_pr' => [
                'name'    => 'Reddit r/PublicRelations',
                'urls'    => ['https://www.reddit.com/r/PublicRelations/new/.rss'],
                'lang'    => 'en',
                'type'    => 'reddit',
                'enabled' => true,
            ],
            'reddit_imprensa' => [
                'name'    => 'Reddit r/imprensa (PT-BR)',
                'urls'    => ['https://www.reddit.com/r/imprensa/new/.rss'],
                'lang'    => 'pt',
                'type'    => 'reddit',
                'enabled' => true,
            ],

            // ── Twitter/X via Nitter (proxy RSS gratuito de hashtag) ─────────
            // Nitter tem várias instâncias — tentamos em ordem
            'twitter_journorequest' => [
                'name'    => 'Twitter #journorequest',
                'urls'    => [
                    'https://nitter.privacydev.net/search/rss?f=tweets&q=%23journorequest',
                    'https://nitter.poast.org/search/rss?f=tweets&q=%23journorequest',
                    'https://nitter.net/search/rss?f=tweets&q=%23journorequest',
                ],
                'lang'    => 'en',
                'type'    => 'twitter',
                'enabled' => true,
            ],
            'twitter_prrequest' => [
                'name'    => 'Twitter #PRrequest',
                'urls'    => [
                    'https://nitter.privacydev.net/search/rss?f=tweets&q=%23prrequest',
                    'https://nitter.poast.org/search/rss?f=tweets&q=%23prrequest',
                    'https://nitter.net/search/rss?f=tweets&q=%23prrequest',
                ],
                'lang'    => 'en',
                'type'    => 'twitter',
                'enabled' => true,
            ],
            'twitter_pautajornalistica' => [
                'name'    => 'Twitter #pautajornalistica (PT-BR)',
                'urls'    => [
                    'https://nitter.privacydev.net/search/rss?f=tweets&q=%23pautajornalistica',
                    'https://nitter.poast.org/search/rss?f=tweets&q=%23pautajornalistica',
                    'https://nitter.net/search/rss?f=tweets&q=%23pautajornalistica',
                ],
                'lang'    => 'pt',
                'type'    => 'twitter',
                'enabled' => true,
            ],
            'twitter_jornalistapauta' => [
                'name'    => 'Twitter #jornalista #pauta (PT-BR)',
                'urls'    => [
                    'https://nitter.privacydev.net/search/rss?f=tweets&q=jornalista+pauta',
                    'https://nitter.poast.org/search/rss?f=tweets&q=jornalista+pauta',
                ],
                'lang'    => 'pt',
                'type'    => 'twitter',
                'enabled' => true,
            ],

            // ── SourceBottle (RSS público por categoria) ─────────────────────
            'sourcebottle_business' => [
                'name'    => 'SourceBottle — Business',
                'urls'    => ['https://www.sourcebottle.com/category/business/feed/'],
                'lang'    => 'en',
                'type'    => 'sourcebottle',
                'enabled' => true,
            ],
            'sourcebottle_technology' => [
                'name'    => 'SourceBottle — Technology',
                'urls'    => ['https://www.sourcebottle.com/category/technology/feed/'],
                'lang'    => 'en',
                'type'    => 'sourcebottle',
                'enabled' => true,
            ],
        ];
    }

    /**
     * Garantir que a tabela de oportunidades existe.
     */
    public static function ensure_table(): void {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE_SUFFIX;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT,
            source_key VARCHAR(64) NOT NULL,
            source_name VARCHAR(120) NOT NULL,
            source_type VARCHAR(32) NOT NULL,
            lang VARCHAR(8) NOT NULL DEFAULT 'en',
            external_id VARCHAR(190) NOT NULL,
            title VARCHAR(500) NOT NULL,
            content TEXT,
            link VARCHAR(500),
            author VARCHAR(120),
            published_at DATETIME NULL,
            collected_at DATETIME NOT NULL,
            relevance_score TINYINT UNSIGNED DEFAULT 0,
            relevance_reason VARCHAR(500) DEFAULT NULL,
            niche_match VARCHAR(120) DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'new',
            user_action VARCHAR(20) DEFAULT NULL,
            generated_pitch LONGTEXT DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY ux_external (source_key, external_id),
            KEY ix_status (status),
            KEY ix_score  (relevance_score),
            KEY ix_collected (collected_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Coletar de todas as fontes habilitadas. Chamado pelo cron.
     *
     * @return array{collected:int, errors:int, sources:array}
     */
    public static function collect_all(): array {
        self::ensure_table();

        $sources         = self::get_sources();
        $enabled_options = get_option('sara_media_enabled_sources', null);
        $lang_filter     = get_option('sara_media_lang_filter', 'both'); // 'both', 'en', 'pt'

        // Filtrar fontes habilitadas
        if (is_array($enabled_options)) {
            $sources = array_intersect_key($sources, array_flip($enabled_options));
        }

        // Filtrar por idioma
        if ($lang_filter !== 'both') {
            $sources = array_filter($sources, fn($s) => $s['lang'] === $lang_filter);
        }

        $collected = 0;
        $errors    = 0;
        $by_source = [];

        foreach ($sources as $key => $source) {
            try {
                $items = self::fetch_source($key, $source);
                $saved = self::save_items($key, $source, $items);
                $by_source[$key] = ['fetched' => count($items), 'saved' => $saved];
                $collected += $saved;
            } catch (\Throwable $e) {
                $errors++;
                $by_source[$key] = ['error' => $e->getMessage()];
                AutopilotLogger::log('media', 'collect_error', 'error',
                    "[{$source['name']}] " . $e->getMessage());
            }
        }

        AutopilotLogger::log('media', 'collect_done', 'success',
            "Coletadas {$collected} oportunidades novas, {$errors} erros");

        return ['collected' => $collected, 'errors' => $errors, 'sources' => $by_source];
    }

    /**
     * Buscar de uma fonte (tenta todas as URLs em ordem até uma funcionar).
     */
    private static function fetch_source(string $key, array $source): array {
        $cache_key = 'sara_media_cache_' . $key;
        $cached    = get_transient($cache_key);
        if (is_array($cached)) return $cached;

        $items = [];
        foreach ($source['urls'] as $url) {
            $response = wp_remote_get($url, [
                'timeout'    => 20,
                'user-agent' => 'SARA-Autopilot-MediaCollector/1.0 (+' . get_site_url() . ')',
                'headers'    => ['Accept' => 'application/rss+xml, application/xml, text/xml'],
            ]);

            if (is_wp_error($response)) continue;
            $code = wp_remote_retrieve_response_code($response);
            if ($code !== 200) continue;

            $body = wp_remote_retrieve_body($response);
            if (empty($body)) continue;

            $items = self::parse_rss($body);
            if (!empty($items)) break; // achou, para de tentar
        }

        // Cache mesmo array vazio (evita martelar fontes que falham)
        set_transient($cache_key, $items, self::CACHE_TTL);
        return $items;
    }

    /**
     * Parsear RSS/Atom genericamente. Funciona com Reddit, Nitter e SourceBottle.
     */
    private static function parse_rss(string $xml_string): array {
        // Suprimir warnings de XML mal formado
        $prev = libxml_use_internal_errors(true);
        $xml  = simplexml_load_string($xml_string);
        libxml_use_internal_errors($prev);

        if (!$xml) return [];

        $items = [];

        // RSS 2.0 (channel/item)
        if (isset($xml->channel->item)) {
            foreach ($xml->channel->item as $node) {
                $item = self::node_to_item($node);
                if ($item) $items[] = $item;
            }
        }
        // Atom (entry)
        elseif (isset($xml->entry)) {
            foreach ($xml->entry as $node) {
                $item = self::node_to_item($node, true);
                if ($item) $items[] = $item;
            }
        }

        return $items;
    }

    private static function node_to_item(\SimpleXMLElement $node, bool $is_atom = false): ?array {
        if ($is_atom) {
            // Atom: title/link/summary/content/published/author
            $title = (string)($node->title ?? '');
            $link  = '';
            if (isset($node->link['href'])) $link = (string)$node->link['href'];
            elseif (isset($node->link))      $link = (string)$node->link;

            $content = (string)($node->content ?? $node->summary ?? '');
            $author  = (string)($node->author->name ?? '');
            $pub     = (string)($node->published ?? $node->updated ?? '');
            $guid    = (string)($node->id ?? $link);
        } else {
            // RSS 2.0
            $title   = (string)($node->title ?? '');
            $link    = (string)($node->link ?? '');
            $content = (string)($node->description ?? '');
            $author  = (string)($node->author ?? $node->{'dc:creator'} ?? '');
            $pub     = (string)($node->pubDate ?? '');
            $guid    = (string)($node->guid ?? $link);
        }

        $title = trim(strip_tags(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if (mb_strlen($title) < 8) return null;

        $content_clean = trim(strip_tags(html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $content_clean = preg_replace('/\s+/u', ' ', $content_clean);
        $content_clean = mb_substr($content_clean, 0, 2000);

        return [
            'external_id'  => mb_substr($guid ?: md5($title . $link), 0, 190),
            'title'        => mb_substr($title, 0, 500),
            'content'      => $content_clean,
            'link'         => mb_substr(trim($link), 0, 500),
            'author'       => mb_substr(trim($author), 0, 120),
            'published_at' => self::parse_date($pub),
        ];
    }

    private static function parse_date(string $raw): ?string {
        if (empty($raw)) return null;
        $ts = strtotime($raw);
        if (!$ts) return null;
        return date('Y-m-d H:i:s', $ts);
    }

    /**
     * Salvar items na tabela. Usa INSERT IGNORE para duplicatas.
     *
     * @return int quantos foram realmente salvos
     */
    private static function save_items(string $source_key, array $source, array $items): int {
        if (empty($items)) return 0;

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;
        $now   = current_time('mysql');
        $saved = 0;

        foreach ($items as $item) {
            // INSERT IGNORE via dbdelta seria complexo; usamos SQL direto com unique key
            $sql = $wpdb->prepare(
                "INSERT IGNORE INTO {$table}
                (source_key, source_name, source_type, lang, external_id, title, content, link, author, published_at, collected_at, status)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, 'new')",
                $source_key,
                $source['name'],
                $source['type'],
                $source['lang'],
                $item['external_id'],
                $item['title'],
                $item['content'],
                $item['link'],
                $item['author'],
                $item['published_at'],
                $now
            );

            $result = $wpdb->query($sql);
            if ($result === 1) $saved++;
        }

        // Limpar oportunidades muito antigas (>14 dias) para não inchar a tabela
        $wpdb->query("DELETE FROM {$table} WHERE collected_at < DATE_SUB(NOW(), INTERVAL 14 DAY)");

        return $saved;
    }

    /** Bootstrap: registrar cron 4x/dia */
    public static function register(): void {
        add_action('sara_media_collect_cron', [__CLASS__, 'cron_collect']);

        if (!wp_next_scheduled('sara_media_collect_cron')) {
            // 4x/dia — a cada 6h
            wp_schedule_event(time() + 60, 'sara_media_6h', 'sara_media_collect_cron');
        }

        add_filter('cron_schedules', function($s) {
            $s['sara_media_6h'] = ['interval' => 6 * HOUR_IN_SECONDS, 'display' => 'SARA Media 6h'];
            return $s;
        });
    }

    /** Disparado pelo cron */
    public static function cron_collect(): void {
        if (get_option('sara_media_enabled', '0') !== '1') return;

        $result = self::collect_all();

        // Após coletar, rodar matching com o nicho do site
        if ($result['collected'] > 0) {
            SaraMediaMatcher::match_pending();
        }
    }
}
