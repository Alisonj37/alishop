<?php
namespace GeoMetodoSEO\Services;

if (!defined('ABSPATH')) { exit; }

/**
 * Validador centralizado de qualidade de H2 — usado por TODOS os fluxos de geração.
 *
 * Antes desta classe, só o SARA Autopilot Auto tinha detecção de H2 vazios.
 * Artigo Individual, Massa, Cluster, YouTube e Glossário NÃƒO tinham — e podiam
 * salvar artigos com H2 sem conteúdo quando o Groq cortava ou o modelo alucinava.
 *
 * Esta classe é o "auditor de qualidade" comum a TODOS os pontos de geração.
 */
class H2QualityValidator {

    /**
     * Detecta H2 vazios ou com pouco conteúdo.
     * Considera "problemático" qualquer H2 com menos de 80 palavras de conteúdo.
     * FAQ, Conclusão, Próximos Passos e Quick Answer são ignorados (podem ser curtos).
     *
     * @param string $content HTML do artigo
     * @return array{has_problem: bool, problematic_h2s: array<int, string>, total_h2: int, thin_h2_count: int}
     */
    public static function detect_thin_h2s(string $content): array {
        $problematic = [];
        $empty_result = [
            'has_problem' => false,
            'problematic_h2s' => [],
            'total_h2' => 0,
            'thin_h2_count' => 0,
        ];

        // Falha graciosa: se conteúdo vazio ou inválido, retorna sem problema (não trava o pipeline)
        if (trim($content) === '') {
            return $empty_result;
        }

        try {
            if (!preg_match_all('/<h2[^>]*>(.*?)<\/h2>/isu', $content, $matches, PREG_OFFSET_CAPTURE)) {
                return $empty_result;
            }
        } catch (\Throwable $e) {
            return $empty_result;
        }

        $h2_count = count($matches[0]);
        if ($h2_count === 0) return $empty_result;

        for ($i = 0; $i < $h2_count; $i++) {
            try {
                $h2_raw = $matches[1][$i][0] ?? '';
                $h2_text = function_exists('wp_strip_all_tags')
                    ? trim(wp_strip_all_tags($h2_raw))
                    : trim(strip_tags($h2_raw));
                $h2_pos  = (int) $matches[0][$i][1];
                $h2_len  = strlen($matches[0][$i][0]);

                // Ignorar seçÃµes que podem ser curtas legitimamente
                if ($h2_text === '' || preg_match('/perguntas\s+frequentes|faq|conclus[aã]o|próximos passos|o que fazer agora|resposta r[áa]pida/iu', $h2_text)) {
                    continue;
                }

                // Conteúdo entre este H2 e o próximo (ou fim do artigo)
                $next_pos = ($i + 1 < $h2_count) ? (int) $matches[0][$i + 1][1] : strlen($content);
                $section  = substr($content, $h2_pos + $h2_len, $next_pos - $h2_pos - $h2_len);
                $text     = function_exists('wp_strip_all_tags') ? wp_strip_all_tags($section) : strip_tags($section);
                $text     = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'â€™\-]*/u', $text, $words);
                $word_count = count($words[0] ?? []);

                if ($word_count < 80) {
                    $h2_short = function_exists('mb_substr')
                        ? mb_substr($h2_text, 0, 60)
                        : substr($h2_text, 0, 60);
                    $problematic[] = $h2_short . ' (' . $word_count . ' palavras — VAZIO)';
                } elseif ($word_count > 600) {
                    // FIX v1.0.0-OVERSIZED-H2: H2 com mais de 600 palavras prejudica SEO/leitura.
                    // Ideal: 250-500 palavras. Acima disso, deve ser dividido em 2 H2 ou usar H3.
                    $h2_short = function_exists('mb_substr')
                        ? mb_substr($h2_text, 0, 60)
                        : substr($h2_text, 0, 60);
                    $problematic[] = $h2_short . ' (' . $word_count . ' palavras — MUITO GRANDE)';
                }
            } catch (\Throwable $e) {
                // Se algum H2 deu erro de parse, pula esse e continua — não trava o pipeline
                continue;
            }
        }

        return [
            'has_problem' => !empty($problematic),
            'problematic_h2s' => $problematic,
            'total_h2' => $h2_count,
            'thin_h2_count' => count($problematic),
        ];
    }

    /**
     * Gera um prompt de expansão pra completar H2 vazios.
     * Usado quando detect_thin_h2s retorna has_problem=true.
     */
    public static function build_expand_prompt(string $content, array $problematic_h2s, string $keyword, int $max_words = 0): string {
        $h2_list = implode("\n- ", array_map(fn($h) => $h, $problematic_h2s));
        $n_problems = count($problematic_h2s);

        // Calcular palavras por H2 respeitando o limite do artigo
        // Se max_words=0, usa 250 palavras por H2 (padrão seguro)
        $words_per_h2 = 250;
        if ($max_words > 0) {
            $current_words = str_word_count(strip_tags($content));
            $available = max(100, $max_words - $current_words);
            $words_per_h2 = max(100, min(300, (int)floor($available / max(1, $n_problems))));
        }

        return "Você é um editor sênior. O artigo abaixo sobre \"{$keyword}\" tem H2 com pouco conteúdo. "
            . "Expanda APENAS esses H2 problemáticos para {$words_per_h2}-" . ($words_per_h2 + 80) . " palavras cada, mantendo qualidade SEO/GEO/AEO.\n\n"
            . "H2 PROBLEMÃTICOS (precisam expansão):\n- {$h2_list}\n\n"
            . "REGRAS:\n"
            . "1. NÃƒO modifique H2 que estão bons. Mantenha-os como estão.\n"
            . "2. Para cada H2 problemático, escreva 2-3 parágrafos com comparacoes, exemplos praticos ou erro+solucao; use dados numericos somente com fonte verificavel.\n"
            . "3. Mantenha o tom e estilo do artigo original.\n"
            . "4. Use linguagem natural — sem 'é fundamental', 'é essencial', 'vamos explorar'.\n"
            . "5. Cada parágrafo: comparacao pratica OU erro+solucao OU insight nao-obvio; nao invente estatisticas.\n"
            . "6. Sem markdown. HTML limpo.\n"
            . "7. LIMITE TOTAL: o artigo final NÃƒO deve ultrapassar {$max_words} palavras.\n\n"
            . "ARTIGO ATUAL:\n{$content}\n\n"
            . "Retorne APENAS o artigo completo expandido (HTML), sem texto fora dele.";
    }

    /**
     * Loga o problema detectado de forma padronizada.
     */
    public static function log_problem(array $detection, int $post_id = 0, string $scope = 'pipeline'): void {
        if (!$detection['has_problem']) return;
        if (!class_exists('\GeoMetodoSEO\Services\LogService')) return;

        \GeoMetodoSEO\Services\LogService::record(
            'pipeline', 'warning',
            "[{$scope}] H2 vazios/genéricos detectados: " . $detection['thin_h2_count'] . '/' . $detection['total_h2'],
            ['action' => 'h2_thin_detected', 'context' => [
                'scope' => $scope,
                'post_id' => $post_id,
                'problematic_h2s' => $detection['problematic_h2s'],
                'total_h2' => $detection['total_h2'],
                'thin_count' => $detection['thin_h2_count'],
            ]]
        );
    }
}
