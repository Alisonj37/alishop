<?php
namespace GeoMetodoSEO\Services;

if (!defined('ABSPATH')) { exit; }

class ContentFormatter {

    public static function extract($response) {
        if (isset($response['choices'][0]['message']['content'])) {
            return $response['choices'][0]['message']['content'];
        }
        if (isset($response['candidates'][0]['content']['parts'][0]['text'])) {
            return $response['candidates'][0]['content']['parts'][0]['text'];
        }
        if (isset($response['content'][0]['text'])) {
            return $response['content'][0]['text'];
        }
        if (isset($response['content'])) {
            return $response['content'];
        }
        return '';
    }

    public static function extractJson($text) {
        if (!is_string($text) || trim($text) === '') return false;

        $text = trim($text);

        // 1. Remover markdown fences: ```json ... ``` ou ```html ... ``` ou ``` ... ```
        $text = preg_replace('/^```(?:json|html|php|xml)?\s*/im', '', $text);
        $text = preg_replace('/\s*```\s*$/im', '', $text);
        $text = trim($text);

        // 2. Tentar parse direto
        $decoded = json_decode($text, true);
        if (is_array($decoded) && !empty($decoded)) return $decoded;

        // 3. Extrair JSON entre { e } (mais robusto para texto antes/depois)
        $start = strpos($text, '{');
        $end   = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $json    = substr($text, $start, $end - $start + 1);
            $decoded = json_decode($json, true);
            if (is_array($decoded) && !empty($decoded)) return $decoded;
        }

        // 4. Tentar reparar JSON com caracteres de controle
        $clean = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text);
        if ($clean !== $text) {
            $decoded = json_decode($clean, true);
            if (is_array($decoded) && !empty($decoded)) return $decoded;
        }

        return false;
    }

    /**
     * Tenta extrair campos de artigo de uma resposta HTML direta (não-JSON).
     * Usado como fallback quando a IA retornou HTML em vez de JSON.
     */
    public static function extractFromHtml(string $html): array {
        if (empty(trim($html))) return [];

        $data = [];

        // Extrair resposta rápida
        if (preg_match('/<div[^>]*(?:geo|sara)-quick-answer[^>]*>.*?<\/div>/is', $html, $m)) {
            $text = wp_strip_all_tags($m[0]);
            $text = preg_replace('/^.*?Resposta\s+Rápida\s*:?\s*/iu', '', $text);
            $data['resumo_snippet'] = '<p>' . esc_html(trim($text)) . '</p>';
            $html = str_replace($m[0], '', $html);
        }

        // Extrair introdução (texto antes do primeiro H2)
        if (preg_match('/^([\s\S]*?)(?=<h2)/i', $html, $m)) {
            $intro = trim($m[1]);
            if (!empty($intro)) $data['introducao'] = $intro;
        }

        // Extrair cada seção H2
        $section_map = [
            'o que é|o que e|definição|definicao|introducao' => 'secao_definicao',
            'como funciona'                                   => 'secao_funcionamento',
            'dados|comparativo|comparação|comparacao'        => 'tabela_comparativa',
            'benefícios|beneficios|vantagens'                 => 'secao_beneficios',
            'guia|escolher|como usar'                         => 'secao_guia',
            'erros|problemas|evitar'                          => 'erros_comuns',
            'tendências|tendencias|2026|2027'                 => 'tendencias_2026',
            'conclusão|conclusao'                             => 'conclusao',
            'perguntas|faq|frequentes'                        => 'faq_texto',
            'dica|especialista|expert'                        => 'dica_especialista',
        ];

        preg_match_all('/<h2[^>]*>(.*?)<\/h2>([\s\S]*?)(?=<h2|$)/i', $html, $sections, PREG_SET_ORDER);
        foreach ($sections as $sec) {
            $heading = strtolower(wp_strip_all_tags($sec[1]));
            $body    = trim($sec[2]);
            if (empty($body)) continue;

            $matched = false;
            foreach ($section_map as $pattern => $key) {
                if (preg_match('/(' . $pattern . ')/iu', $heading)) {
                    if (empty($data[$key])) $data[$key] = $body;
                    $matched = true;
                    break;
                }
            }
        }

        // Extrair title_seo da tag <title> ou primeiro H1
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/i', $html, $m)) {
            $data['title_seo'] = wp_strip_all_tags($m[1]);
        }

        return $data;
    }
}
