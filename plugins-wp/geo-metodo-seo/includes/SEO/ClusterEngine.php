<?php
namespace GeoMetodoSEO\SEO;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\Services\ArticlePipeline;
use GeoMetodoSEO\AI\ProviderResolver;
use GeoMetodoSEO\Repositories\ClusterRepository;

class ClusterEngine {

    private $repository;

    public function __construct() {
        $this->repository = new ClusterRepository();
    }

    public function create_cluster($main_keyword, $satellite_keywords = []) {
        // Artigo pilar e satélites usam o provider configurado para Cluster.
        $cluster_provider = ProviderResolver::for('cluster_generation');
        $cluster_model    = ProviderResolver::modelFor('cluster_generation', $cluster_provider);
        $pipeline_pillar = new ArticlePipeline($cluster_provider, $cluster_model);
        $pillar_id       = $pipeline_pillar->process($main_keyword);

        $pipeline_satellite = new ArticlePipeline($cluster_provider, $cluster_model);
        $satellite_ids      = [];

        foreach ($satellite_keywords as $keyword) {
            $post_id = $pipeline_satellite->process($keyword);
            if ($post_id) {
                $satellite_ids[] = $post_id;
            }
        }

        $cluster_id = $this->repository->create($pillar_id, $satellite_ids, $main_keyword);

        return [
            'cluster_id' => $cluster_id,
            'pillar_id'  => $pillar_id,
            'satellites' => $satellite_ids
        ];
    }
}
