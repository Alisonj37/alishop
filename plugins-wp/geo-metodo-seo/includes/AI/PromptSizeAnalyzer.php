<?php
namespace GeoMetodoSEO\AI;

if (!defined('ABSPATH')) { exit; }

/**
 * PromptSizeAnalyzer — extrai o tamanho alvo do prompt e calcula max_tokens proporcional.
 *
 * Por que existe:
 * - Antes: max_tokens fixo em 8000 → IA gerava o que quisesse, ignorando "target_words"
 * - Agora: lê "META: X palavras", "MÁXIMO: Y palavras" do prompt e amarra max_tokens
 *   ao tamanho real esperado. Se user pede 1500 palavras, max_tokens = ~2400 tokens,
 *   FORÇANDO a IA a parar próximo do tamanho pedido.
 *
 * Também verifica o tamanho do prompt de entrada para evitar overflow em modelos
 * com janela limitada (ex: Groq on_demand com TPM de 8000 tokens).
 *
 * Conversão palavras→tokens:
 * - PT-BR: ~1.5 tokens por palavra
 * - EN: ~1.3 tokens por palavra
 * - Margem de segurança: 1.7x para tags HTML, JSON wrappers, espaços
 */
class PromptSizeAnalyzer {

    // Limites de context window por provider (input + output juntos)
    // Conservador: usar 75% do limite real para ter margem segura
    // Groq on_demand TPM varia por modelo — usar o mais restritivo como base
    const PROVIDER_CONTEXT_LIMITS = [
        'groq'       => 5000,   // llama-3.3-70b on_demand: 6000 TPM → 75% = 4500; gpt-oss-120b: 8000 → 6000. Usar 5000 para cobrir ambos.
        'gemini'     => 30000,  // gemini-3.1-flash-lite: 1M context — sem problema prático
        'openai'     => 16000,  // gpt-4.1: 128k context — sem problema prático
        'claude'     => 16000,  // claude-sonnet-4-6: 200k context — sem problema prático
        'naga'       => 7000,   // conservador — depende do modelo roteado
        'perplexity' => 10000,  // sonar-pro: 127k context — sem problema prático
    ];

    /**
     * Calcula max_tokens ideal baseado no tamanho do artigo no prompt.
     * Se não encontrar tamanho no prompt, retorna fallback alto (8000) — comportamento atual.
     *
     * @param string $prompt    O prompt completo
     * @param int    $fallback  Valor padrão se não achar tamanho no prompt
     * @param string $provider  Provider alvo — usado para respeitar limite de context window
     */
    public static function calculate_max_tokens(string $prompt, int $fallback = 8000, string $provider = ''): int {
        $patterns = [
            '/m[áa]ximo\s+absoluto[:\s]+(\d{3,5})/iu',
            '/m[áa]ximo\s+(\d{3,5})\s*palavras/iu',
            '/meta\s+de\s+tamanho[:\s]+(\d{3,5})/iu',
            '/meta[:\s]+(\d{3,5})\s*palavras/iu',
            '/tamanho\s+alvo[:\s]+(\d{3,5})/iu',
            '/tamanho[:\s]+(\d{3,5})\s*palavras/iu',
            '/total\s+obrigat[óo]rio[:\s]+(\d{3,5})/iu',
            '/(\d{3,5})\s*palavras\s*(?:alvo|total|m[áa]ximo)/iu',
            '/entre\s+\d{3,5}\s+e\s+(\d{3,5})\s*palavras/iu',
        ];

        $max_words = 0;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $prompt, $m)) {
                $found = (int) $m[1];
                if ($found >= 500 && $found <= 8000) {
                    $max_words = max($max_words, $found);
                }
            }
        }

        if ($max_words === 0) {
            $max_tokens_output = min($fallback, 4000);
        } else {
            // PT-BR: ~1.5 tokens/palavra. Margem 1.6x para acomodar HTML + tags.
            //   small  1500 palavras → 2400 tokens → ~45s
            //   medium 2500 palavras → 4000 tokens → ~73s
            //   large  3500 palavras → 5600 tokens → ~100s
            // Limite de 6000 tokens permite artigos grandes completos sem corte.
            // O timeout do provider (120s) acomoda artigos large.
            $max_tokens_output = (int) ceil($max_words * 1.6);
            $max_tokens_output = max(1500, min(6000, $max_tokens_output));
        }

        // FIX-GROQ: verificar se input + output cabe no context window do provider
        // Estimativa do prompt de entrada: ~0.3 tokens por caractere (PT-BR)
        if ($provider !== '') {
            $provider_key   = strtolower(trim($provider));
            $context_limit  = self::PROVIDER_CONTEXT_LIMITS[$provider_key] ?? 0;
            if ($context_limit > 0) {
                $estimated_input_tokens = (int) ceil(strlen($prompt) * 0.3);
                $available_for_output   = $context_limit - $estimated_input_tokens;
                if ($available_for_output < 500) {
                    // Prompt de entrada já ocupa quase tudo — retornar mínimo para a IA conseguir responder
                    return 500;
                }
                // Limitar max_tokens_output ao que cabe no context window
                $max_tokens_output = min($max_tokens_output, $available_for_output);
            }
        }

        return max(500, $max_tokens_output);
    }
}
