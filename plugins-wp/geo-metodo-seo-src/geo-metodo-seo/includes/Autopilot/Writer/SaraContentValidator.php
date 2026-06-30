<?php
declare(strict_types=1);

namespace GeoMetodoSEO\Autopilot\Writer;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\Autopilot\Shared\AutopilotLogger;

/**
 * SaraContentValidator — Bloqueia conteúdo genérico, frases-template e dados inventados.
 * Rejeita tudo que parece "artigo de Wikipédia traduzido" ou "preencheu campos genericamente".
 *
 * @since 1.0.0
 */
class SaraContentValidator {

    /** Frases proibidas — qualquer ocorrência rejeita o artigo */
    private const FORBIDDEN_PHRASES = [
        // Aberturas de template
        '/imagine[mn]?\s+(?:que|ter|se|você)/iu'                    => 'Template: "imagine(m)..."',
        '/no\s+mundo\s+(?:atual|de\s+hoje)/iu'                      => 'Clichê: "no mundo atual"',
        '/nos\s+dias\s+de\s+hoje/iu'                                => 'Clichê: "nos dias de hoje"',

        // Conclusões fracas (incluindo "para sintetizar")
        '/em\s+resumo[,\s]/iu'                                       => 'Template: "em resumo"',
        '/para\s+sintetizar/iu'                                      => 'Template: "para sintetizar"',
        '/sintetizando[,\s]/iu'                                       => 'Template: "sintetizando"',
        '/em\s+conclus[ãa]o/iu'                                      => 'Template: "em conclusão"',
        '/por\s+fim[,\s]/iu'                                         => 'Template: "por fim"',

        // CTAs clichê
        '/se\s+você\s+está\s+pronto/iu'                            => 'CTA clichê: "se você está pronto"',
        '/comece\s+hoje\s+mesmo/iu'                                 => 'CTA clichê: "comece hoje mesmo"',
        '/não\s+perca\s+tempo/iu'                                   => 'CTA clichê: "não perca tempo"',
        '/iniciar\s+sua\s+jornada/iu'                               => 'CTA clichê: "iniciar sua jornada"',
        '/(?:o|este)\s+primeiro\s+passo\s+crucial/iu'              => 'CTA clichê: "primeiro passo crucial"',
        '/levar\s+(?:sua|a)\s+(?:empresa|carreira|negócio)\s+ao\s+próximo\s+nível/iu' => 'CTA clichê: "ao próximo nível"',
        '/à\s+frente\s+da\s+curva/iu'                              => 'Clichê: "à frente da curva"',

        // Frases de transição vazias
        '/no\s+final\s+do\s+dia/iu'                                => 'Template: "no final do dia"',
        '/é\s+importante\s+(?:notar|ressaltar|destacar|mencionar)/iu' => 'Vazio: "é importante notar/ressaltar"',
        '/vale\s+a\s+pena\s+(?:ressaltar|mencionar|lembrar)/iu'    => 'Vazio: "vale a pena ressaltar"',
        '/como\s+mencionado\s+anteriormente/iu'                    => 'Template: "como mencionado anteriormente"',
        '/não\s+podemos\s+deixar\s+de\s+mencionar/iu'              => 'Template: "não podemos deixar de mencionar"',
        '/com\s+a\s+crescente\s+(?:competição|concorrência)/iu'    => 'Clichê: "com a crescente competição"',
        '/cada\s+vez\s+mais\s+saturad[oa]/iu'                      => 'Clichê: "cada vez mais saturado"',
        '/com\s+as\s+tendências\s+atuais/iu'                       => 'Vazio: "com as tendências atuais"',

        // Frases vagas de força (sem dado que justifique)
        '/é\s+fundamental\s+(?:para|que)/iu'                       => 'Vazio: "é fundamental"',
        '/é\s+essencial\s+(?:para|que)/iu'                         => 'Vazio: "é essencial"',
        '/é\s+crucial\s+(?:para|que)/iu'                           => 'Vazio: "é crucial"',
        '/é\s+indispensável/iu'                                     => 'Vazio: "é indispensável"',

        // Verbos genéricos
        '/transformar\s+a\s+forma\s+como/iu'                       => 'Clichê: "transformar a forma como"',
        '/explorar\s+as\s+opções\s+disponíveis/iu'                 => 'Clichê: "explorar opções disponíveis"',
        '/encontrar\s+a\s+solução\s+certa/iu'                      => 'Clichê: "encontrar a solução certa"',
        '/decisões\s+precisam\s+ser\s+tomadas\s+rapidamente/iu'    => 'Clichê: "decisões precisam ser tomadas rapidamente"',
    ];

    /** Padrões de início de introdução proibidos (primeiros 200 chars) */
    private const FORBIDDEN_INTRO_START = [
        '/^(?:o\s+que\s+é|a\s+definição\s+de|em\s+termos\s+simples|de\s+forma\s+simples|basicamente|essencialmente)/iu'
            => 'Introdução genérica — não comece com definição',
        '/^(?:no\s+mundo\s+atual|nos\s+dias\s+de\s+hoje|atualmente|hoje\s+em\s+dia)/iu'
            => 'Introdução clichê — não comece com "no mundo atual / hoje em dia"',
    ];

    /** Padrões obrigatórios — pelo menos 1 deve estar presente */
    private const REQUIRED_DATA_PATTERNS = [
        '/\b\d{1,3}(?:[.,]\d{3})*(?:[,.]\d+)?\s*%/u',       // percentual
        '/R\$\s*\d{1,3}(?:[.,]\d{3})*(?:[,.]\d+)?/u',       // moeda BRL
        '/\$\s*\d{1,3}(?:[.,]\d{3})*(?:[,.]\d+)?/u',        // moeda USD
        '/\b\d{2,}[.,]\d+x\s+(?:mais|menos)/iu',            // multiplicador (3.4x mais rápido)
        '/\b\d{4,}\s+(?:casos|empresas|sites|usuários|clientes|testes)/iu', // contagens grandes
    ];

    /**
     * Valida conteúdo HTML completo.
     * @return array{valid: bool, errors: string[], warnings: string[]}
     */
    public function validate(string $content, string $title, int $word_count_target): array {
        $errors   = [];
        $warnings = [];
        $text     = strip_tags($content);

        // ── 1. Frases proibidas ────────────────────────────────────────
        foreach (self::FORBIDDEN_PHRASES as $pattern => $reason) {
            if (preg_match($pattern, $text)) {
                $errors[] = $reason;
                $this->log_fail($title, $reason);
            }
        }

        // ── 2. Anos inventados / suspeitos ─────────────────────────────
        // Ano corrente é OK; futuro próximo só com fonte explícita
        $current_year = (int) date('Y');
        $next_year    = $current_year + 1;

        if (preg_match_all('/\b(20\d{2})\b/', $text, $year_matches)) {
            foreach (array_unique($year_matches[1]) as $year) {
                $year_int = (int) $year;
                if ($year_int > $next_year) {
                    $errors[] = "Ano futuro inventado: {$year}";
                    $this->log_fail($title, "ano futuro: {$year}");
                }
            }
        }

        // ── 3. Fontes inventadas (Statista/Gartner/McKinsey + ano inventado) ──
        // Padrão: "Segundo X (ANO)" sem URL ou link
        if (preg_match_all(
            '/segundo\s+(?:um\s+)?(?:relatório\s+d[aoe]\s+)?(McKinsey|Gartner|Statista|Forrester|IDC|Deloitte|PwC|KPMG)\s*\((\d{4})\)/iu',
            $text, $source_matches, PREG_SET_ORDER
        )) {
            foreach ($source_matches as $match) {
                // Se a fonte aparece sem link próximo, é provavelmente inventada
                $position = strpos($text, $match[0]);
                $context  = substr($text, $position, 300);
                if (!preg_match('/https?:\/\//', $context)) {
                    $errors[] = "Fonte sem link verificável: {$match[1]} ({$match[2]})";
                    $this->log_fail($title, "fonte sem link: {$match[1]}");
                }
            }
        }


        // ── 3b. Casos reais, métodos próprios e autoridade falsa ─────────────
        if (preg_match('/<h[23][^>]*>\s*(?:caso\s+real|hist[oó]ria\s+real|exemplo\s+real|estudo\s+de\s+caso)/iu', $content)) {
            $errors[] = 'Caso real/estudo de caso detectado sem briefing verificável';
            $this->log_fail($title, 'caso real inventado');
        }
        if (preg_match('/\b(?:m[eé]todo|framework|f[oó]rmula)\s+[A-Z](?:\.[A-Z]){2,}\.?/u', $text)) {
            $errors[] = 'Método autoral/sigla própria possivelmente inventado';
            $this->log_fail($title, 'metodo autoral inventado');
        }
        if (preg_match('/\b(?:mais\s+de\s+)?\d+\s+(?:compras|casos|clientes|aparelhos)\s+(?:reais\s+)?(?:que\s+)?(?:analisei|testei|acompanhei)/iu', $text)) {
            $errors[] = 'Experiência pessoal quantitativa possivelmente inventada';
            $this->log_fail($title, 'experiencia pessoal inventada');
        }

        // ── 4. Introdução genérica ─────────────────────────────────────
        $first_200 = trim(substr($text, 0, 300));
        // Pular box de Resposta Rápida se existir
        $first_200 = preg_replace('/^.*?Resposta\s+Rápida:\s*/iu', '', $first_200);
        $first_200 = trim($first_200);

        foreach (self::FORBIDDEN_INTRO_START as $pattern => $reason) {
            if (preg_match($pattern, $first_200)) {
                $errors[] = $reason;
                $this->log_fail($title, $reason);
            }
        }

        // ── 5. Word count real ──────────────────────────────────────
        // FIX estabilidade: não rejeitar artigo quase pronto por pequena diferença de palavras.
        // Rejeição + nova chamada de IA era a causa principal de 504 Gateway Timeout.
        $actual_words = str_word_count($text);
        $target_ratio = $word_count_target >= 2000 ? 0.85 : 0.90;
        $soft_min_words = (int)floor($word_count_target * $target_ratio);
        $hard_min_words = max(500, $soft_min_words);
        if ($actual_words < $hard_min_words) {
            $errors[] = "Word count muito baixo: {$actual_words}/{$word_count_target} (mín seguro {$hard_min_words})";
            $this->log_fail($title, "word_count_fail: {$actual_words} < {$hard_min_words}");
        } elseif ($actual_words < (int)floor($word_count_target * 0.90)) {
            $warnings[] = "Word count abaixo da meta, mas aceitável para evitar timeout: {$actual_words}/{$word_count_target}";
        }

        // ── 6. Dados específicos ────────────────────────────────────────
        // 1.0.0: não reprovar por falta de números. Exigir números incentiva a IA
        // a inventar preços, estatísticas ou percentuais. Números só são bons quando confirmados.
        $has_data = false;
        foreach (self::REQUIRED_DATA_PATTERNS as $pattern) {
            if (preg_match($pattern, $text)) { $has_data = true; break; }
        }
        if (!$has_data) {
            $warnings[] = 'Sem números específicos confirmados — aceitável quando o briefing não fornece dados verificáveis';
        }

        $max_words = (int)ceil($word_count_target * 1.15);
        if ($actual_words > $max_words) {
            $warnings[] = "Word count acima da meta: {$actual_words}/{$word_count_target} (máx recomendado {$max_words})";
        }

        // ── 7. Estrutura mínima (H2 + parágrafos) ──────────────────────
        $h2_count = preg_match_all('/<h2[^>]*>/i', $content);
        if ($h2_count < 4) {
            $errors[] = "Apenas {$h2_count} H2s (mínimo 4)";
        }

        // ── 7b. Markdown literal NÃO permitido (sinal de prompt mal seguido) ──
        if (preg_match('/^##\s+\S/m', $content) || preg_match('/^###\s+\S/m', $content)) {
            $errors[] = 'Markdown literal detectado (## ou ###) — IA não renderizou em HTML';
            $this->log_fail($title, 'markdown literal detectado');
        }
        if (substr_count($content, '**') > 4) {
            $errors[] = 'Negrito em markdown (**) detectado — use <strong> em HTML';
        }

        // ── 8. Parágrafos órfãos de 1 frase (proibido) ────────────────
        if (preg_match_all('/<p[^>]*>([^<]+)<\/p>/i', $content, $p_matches)) {
            $orphan_count = 0;
            foreach ($p_matches[1] as $p_text) {
                $sentences = preg_split('/[.!?]+/', trim($p_text));
                $sentences = array_filter($sentences, fn($s) => mb_strlen(trim($s)) > 5);
                if (count($sentences) === 1 && mb_strlen($p_text) > 20 && mb_strlen($p_text) < 200) {
                    $orphan_count++;
                }
            }
            if ($orphan_count > count($p_matches[1]) * 0.3) {
                $warnings[] = "Muitos parágrafos de 1 frase ({$orphan_count})";
            }
        }

        return [
            'valid'         => empty($errors),
            'errors'        => $errors,
            'warnings'      => $warnings,
            'actual_words'  => $actual_words,
            'h2_count'      => $h2_count,
        ];
    }

    private function log_fail(string $title, string $reason): void {
        $log_file = WP_CONTENT_DIR . '/sara-logs/writer.log';
        $dir = dirname($log_file);
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
            file_put_contents($dir . '/.htaccess', 'Deny from all');
        }
        file_put_contents(
            $log_file,
            '[' . date('Y-m-d H:i:s') . '] [WRITER] content_fail: ' . $reason . ' em "' . $title . '"' . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
        AutopilotLogger::log('writer', 'validator_fail', 'warning', $reason);
    }
}
