<?php
declare(strict_types=1);

namespace GeoMetodoSEO\Autopilot\Brain;

if (!defined('ABSPATH')) { exit; }

/**
 * SaraCategoryFilter — Gerencia categorias ativas para geração de conteúdo.
 * O usuário seleciona 2-5 categorias. O Brain gera N títulos por categoria por dia.
 * Integra com as categorias reais do WordPress (não só as predefinidas).
 *
 * @since 1.0.0 (SARA Brain v2.0)
 * @since 1.0.0 BUG FIX: garantir que SOMENTE categorias selecionadas são usadas
 */
class SaraCategoryFilter {

    /** Categorias predefinidas do nicho (mapeia slug → label amigável) */
    public static function available_categories(): array {
        // Combinar categorias predefinidas com categorias reais do WordPress
        $predefined = [
            'wordpress'        => 'WordPress',
            'tecnologia-ia'    => 'Tecnologia e IA',
            'tecnologia'       => 'Tecnologia',
            'seo-marketing'    => 'SEO e Marketing',
            'seo'              => 'SEO',
            'marketing-digital'=> 'Marketing Digital',
            'geo'              => 'GEO (Generative Engine Optimization)',
            'llm'              => 'LLMs e Modelos de IA',
            'aeo'              => 'AEO (Answer Engine Optimization)',
        ];

        // Adicionar categorias reais do WordPress que não estão na lista predefinida
        $wp_categories = get_categories(['hide_empty' => false, 'number' => 30]);
        foreach ($wp_categories as $cat) {
            if (!isset($predefined[$cat->slug])) {
                $predefined[$cat->slug] = $cat->name;
            }
        }

        // Expor globalmente (usado pelo SaraApiClient fallback e admin)
        $GLOBALS['SARA_AVAILABLE_CATEGORIES'] = $predefined;

        return $predefined;
    }

    /** Slugs das categorias atualmente ativas (salvas em wp_option) */
    private array $active;

    public function __construct() {
        $saved        = get_option('sara_active_categories', []);
        $this->active = is_array($saved) ? array_values(array_filter($saved)) : [];
    }

    /**
     * Definir categorias ativas (máximo 5, mínimo 2).
     * @param string[] $selected Array de slugs
     */
    public function set_active_categories(array $selected): void {
        $available = array_keys(self::available_categories());
        $valid     = array_intersect($available, $selected);
        $valid     = array_slice(array_values($valid), 0, 5);
        $this->active = $valid;
        update_option('sara_active_categories', $valid);
    }

    /**
     * Retorna SOMENTE as categorias selecionadas pelo usuário.
     * Filtra qualquer slug que não exista mais no WordPress.
     *
     * @since 1.0.0 BUG FIX: filtrar categorias que não existem mais ou foram desmarcadas
     * @return string[] Slugs das categorias ativas
     */
    public function get_active_categories(): array {
        if (empty($this->active)) return [];

        $available = array_keys(self::available_categories());
        // Manter apenas slugs que ainda estão na lista de disponíveis
        $filtered = array_values(array_intersect($this->active, $available));

        return $filtered;
    }

    /**
     * Validar estritamente se um slug está nas categorias ativas.
     * Use isso antes de planejar ou agendar para uma categoria.
     *
     * @since 1.0.0
     */
    public function is_active_strict(string $slug): bool {
        return in_array($slug, $this->get_active_categories(), true);
    }

    public function is_active(string $category): bool {
        return in_array($category, $this->active, true);
    }

    public function count(): int {
        return count($this->get_active_categories());
    }

    public function has_minimum(): bool {
        return $this->count() >= 2;
    }

    /**
     * Resolver categoria para uso no WordPreess (retornar term_id).
     * Mapeia slug da lista de available_categories para category ID real.
     */
    public function resolve_wp_term_id(string $slug): ?int {
        // Tentar pelo slug diretamente
        $term = get_term_by('slug', $slug, 'category');
        if ($term && !is_wp_error($term)) return $term->term_id;

        // Tentar pelo nome amigável
        $available = self::available_categories();
        if (isset($available[$slug])) {
            $term = get_term_by('name', $available[$slug], 'category');
            if ($term && !is_wp_error($term)) return $term->term_id;
        }

        return null;
    }

    /**
     * Retornar nome amigável de uma categoria.
     */
    public function get_label(string $slug): string {
        $available = self::available_categories();
        return $available[$slug] ?? ucwords(str_replace('-', ' ', $slug));
    }
}
