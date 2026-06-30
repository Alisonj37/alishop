<?php
namespace GeoMetodoSEO\License;

if (!defined('ABSPATH')) { exit; }

class LicenseManager {

    const TRIAL_LIMIT    = 5;
    const VALIDATE_URL   = 'https://aiconteudo.com.br/wp-json/aic/v1/validate';
    const DEACTIVATE_URL = 'https://aiconteudo.com.br/wp-json/aic/v1/deactivate';
    const CACHE_KEY      = 'geo_license_cache';
    const CACHE_EXPIRY   = 86400;

    // ─────────────────────────────────────────────────────────────────────────
    // CHAVE MASTER — uso pessoal, funciona em QUALQUER domínio sem restrição.
    // Verificada via hash SHA-256. Nunca enviada ao servidor externo.
    // ─────────────────────────────────────────────────────────────────────────
    private const MASTER_KEY_HASH = '55564affc034a95d22dcd6e70ace70dab7142918da6a3b7b775f95bdf082e30a';

    private const MASTER_FEATURES = [
        'bulk_generation','cluster_seo','all_providers','multisite',
        'export_settings','unlimited_sites','unlimited_articles',
        'all_models','all_features',
    ];

    const PLAN_FEATURES = [
        'trial'  => [],
        'basic'  => ['bulk_generation','cluster_seo','all_providers'],
        'pro'    => ['bulk_generation','cluster_seo','all_providers'],
        'agency' => ['bulk_generation','cluster_seo','all_providers','multisite','export_settings'],
        'master' => ['bulk_generation','cluster_seo','all_providers','multisite','export_settings',
                     'unlimited_sites','unlimited_articles','all_models','all_features'],
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // Verificação master — local, sem rede, via hash
    // ─────────────────────────────────────────────────────────────────────────

    private static function isMasterKey(string $key): bool {
        if (empty($key)) return false;
        return hash_equals(self::MASTER_KEY_HASH, hash('sha256', trim($key)));
    }

    private static function getMasterData(): array {
        return [
            'plan'    => 'master',
            'status'  => 'active',
            'domain'  => '*',
            'message' => 'Licença Master — acesso irrestrito a todos os domínios.',
            'features'=> self::MASTER_FEATURES,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Consultas principais
    // ─────────────────────────────────────────────────────────────────────────

    public static function getPlan(): string {
        return self::getLicenseData()['plan'] ?? 'trial';
    }

    public static function getStatus(): string {
        return self::getLicenseData()['status'] ?? 'trial';
    }

    /**
     * A licença está expirada/inativa?
     * Master nunca expira. Trial não "expira" por data (usa contador).
     * Planos pagos (mensal/anual): expiram quando o servidor diz status != active,
     * OU quando a data de expiração já passou.
     */
    public static function isExpired(): bool {
        $data = self::getLicenseData();
        $plan = $data['plan'] ?? 'trial';

        if ($plan === 'master') return false;     // master nunca expira
        if ($plan === 'trial')  return false;     // trial usa contador, não data

        // Planos pagos: status precisa ser "active"
        $status = $data['status'] ?? '';
        if ($status !== 'active') return true;    // expired, cancelled, suspended, etc

        // Checagem extra por data de expiração, se o servidor fornecer
        $expires = $data['expires_at'] ?? ($data['valid_until'] ?? ($data['expires'] ?? ''));
        if (!empty($expires)) {
            $ts = is_numeric($expires) ? (int)$expires : strtotime((string)$expires);
            if ($ts && $ts < time()) return true; // data já passou
        }
        return false;
    }

    public static function can(string $feature): bool {
        $plan = self::getPlan();
        if ($plan === 'master') return true;
        return in_array($feature, self::PLAN_FEATURES[$plan] ?? [], true);
    }

    public static function isActive(): bool {
        $plan = self::getPlan();
        if ($plan === 'trial') return false;       // trial não é "ativo" (licença paga)
        if ($plan === 'master') return true;       // master sempre ativo
        // Planos pagos: ativo somente se não estiver expirado/inativo
        return !self::isExpired();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Trial
    // ─────────────────────────────────────────────────────────────────────────

    public static function getTrialCount(): int {
        return (int) get_option('geo_trial_count', 0);
    }

    public static function isTrialExpired(): bool {
        $plan = self::getPlan();
        if ($plan === 'master' || $plan !== 'trial') return false;
        return self::getTrialCount() >= self::TRIAL_LIMIT;
    }

    public static function canGenerate(): bool {
        $plan   = self::getPlan();
        $status = self::getStatus();

        // Master: sempre pode
        if ($plan === 'master') return true;

        // ⚠️ CRÍTICO: quando uma licença paga VENCE, o servidor responde
        // status="expired" (ou disabled/cancelled/site_limit) E rebaixa o plan
        // para "trial". Então NÃO basta olhar o plano — é preciso checar o status.
        // Se o status indica licença inválida/vencida, bloqueia TUDO, mesmo que
        // o plano tenha sido rebaixado para "trial".
        $blocked_status = ['expired', 'disabled', 'cancelled', 'suspended', 'banned', 'site_limit', 'invalid', 'domain_mismatch'];
        if (in_array($status, $blocked_status, true)) {
            // Exceção: trial legítimo (sem chave) tem status "trial" e continua
            // funcionando pelo contador — esse caso NÃO está na lista acima.
            return false;
        }

        // Planos pagos ativos: confirmar que não expiraram por data
        if ($plan !== 'trial') {
            return !self::isExpired();
        }

        // Trial legítimo (status "trial"): limitado por contador de gerações
        return !self::isTrialExpired();
    }

    public static function incrementTrialCount(): void {
        if (self::getPlan() === 'trial') {
            update_option('geo_trial_count', self::getTrialCount() + 1);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Providers
    // ─────────────────────────────────────────────────────────────────────────

    public static function getAllowedProviders(): array {
        if (self::getPlan() === 'master' || self::can('all_providers')) {
            return ['openai','groq','gemini','claude','perplexity','naga'];
        }
        return ['openai','groq','naga'];
    }

    public static function isProviderAllowed(string $provider): bool {
        return in_array($provider, self::getAllowedProviders(), true);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Dados da licença (com cache)
    // ─────────────────────────────────────────────────────────────────────────

    public static function getLicenseData(): array {
        $key = get_option('geo_license_key', '');

        // Master: verificação local, sem cache, sem rede
        if (!empty($key) && self::isMasterKey($key)) {
            return self::getMasterData();
        }

        // Cache para chaves normais
        $cached = get_transient(self::CACHE_KEY);
        if ($cached !== false && is_array($cached)) {
            // Segurança: impede que cache diga "master" se a chave não for master
            if (($cached['plan'] ?? '') === 'master') {
                delete_transient(self::CACHE_KEY);
            } else {
                return $cached;
            }
        }

        if (empty($key)) {
            $result = ['plan' => 'trial', 'status' => 'trial'];
            set_transient(self::CACHE_KEY, $result, self::CACHE_EXPIRY);
            return $result;
        }

        return self::validate();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Validar com servidor + verificação de domínio para chaves de clientes
    // ─────────────────────────────────────────────────────────────────────────

    public static function validate(): array {
        $key = get_option('geo_license_key', '');

        if (empty($key)) {
            $result = ['plan' => 'trial', 'status' => 'trial'];
            set_transient(self::CACHE_KEY, $result, self::CACHE_EXPIRY);
            return $result;
        }

        // Master nunca vai ao servidor
        if (self::isMasterKey($key)) {
            return self::getMasterData();
        }

        $domain = self::getDomainRoot();

        $response = wp_remote_post(self::VALIDATE_URL, [
            'timeout'   => 15,
            'sslverify' => true,
            'body'      => [
                'license_key'    => $key,
                'domain'         => $domain,
                'plugin_version' => defined('GEO_METODO_SEO_VERSION') ? GEO_METODO_SEO_VERSION : '1.0.0',
            ],
        ]);

        if (is_wp_error($response)) {
            $last = get_option('geo_license_last_valid', []);
            if (!empty($last)) {
                // Offline: verificar se domínio salvo bate com o atual
                $saved_domain = $last['domain'] ?? '';
                if (!empty($saved_domain) && $saved_domain !== '*' && self::getDomainRoot('https://' . $saved_domain) !== $domain) {
                    return ['plan' => 'trial', 'status' => 'domain_mismatch',
                            'message' => 'Esta licença está registrada para outro domínio.'];
                }
                set_transient(self::CACHE_KEY, $last, HOUR_IN_SECONDS);
                return $last;
            }
            return ['plan' => 'trial', 'status' => 'error',
                    'message' => 'Servidor indisponível: ' . $response->get_error_message()];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($data)) {
            return ['plan' => 'trial', 'status' => 'error', 'message' => 'Resposta inválida do servidor.'];
        }

        // Verificação de domínio — servidor retorna o domínio cadastrado na licença
        $licensed_domain = $data['domain'] ?? '';
        if (!empty($licensed_domain) && $licensed_domain !== '*') {
            $licensed_root = self::getDomainRoot('https://' . $licensed_domain);
            if ($licensed_root !== $domain) {
                $result = [
                    'plan'    => 'trial',
                    'status'  => 'domain_mismatch',
                    'message' => sprintf(
                        'Esta licença pertence ao domínio "%s". Domínio atual: "%s". Entre em contato para transferir.',
                        $licensed_domain,
                        $domain
                    ),
                ];
                set_transient(self::CACHE_KEY, $result, HOUR_IN_SECONDS);
                return $result;
            }
        }

        set_transient(self::CACHE_KEY, $data, self::CACHE_EXPIRY);

        if (($data['status'] ?? '') === 'active') {
            update_option('geo_license_last_valid', $data);
        }

        return $data;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Ativar licença
    // ─────────────────────────────────────────────────────────────────────────

    public static function activate(string $key): array {
        $key = sanitize_text_field($key);
        if (empty($key)) {
            return ['status' => 'error', 'message' => 'Chave de licença não pode ser vazia.'];
        }

        // Master: ativar localmente
        if (self::isMasterKey($key)) {
            update_option('geo_license_key', $key);
            delete_transient(self::CACHE_KEY);
            $data = self::getMasterData();
            update_option('geo_license_last_valid', $data);
            return $data;
        }

        $domain = self::getDomainRoot();

        $response = wp_remote_post(self::VALIDATE_URL, [
            'timeout'   => 20,
            'sslverify' => true,
            'body'      => [
                'license_key'    => $key,
                'domain'         => $domain,
                'plugin_version' => defined('GEO_METODO_SEO_VERSION') ? GEO_METODO_SEO_VERSION : '1.0.0',
                'action'         => 'activate',
            ],
        ]);

        if (is_wp_error($response)) {
            return ['status' => 'error', 'message' => 'Erro de conexão: ' . $response->get_error_message()];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            return ['status' => 'error', 'message' => 'Resposta inválida do servidor.'];
        }

        // Verificar domínio na ativação
        $licensed_domain = $data['domain'] ?? '';
        if (!empty($licensed_domain) && $licensed_domain !== '*') {
            if (self::getDomainRoot('https://' . $licensed_domain) !== $domain) {
                return [
                    'status'  => 'domain_mismatch',
                    'message' => sprintf(
                        'Esta licença pertence ao domínio "%s". Não pode ser ativada em "%s".',
                        $licensed_domain, $domain
                    ),
                ];
            }
        }

        if (($data['status'] ?? '') === 'active') {
            update_option('geo_license_key', $key);
            delete_transient(self::CACHE_KEY);
            update_option('geo_license_last_valid', $data);
        }

        return $data;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Desativar licença
    // ─────────────────────────────────────────────────────────────────────────

    public static function deactivate(): array {
        $key    = get_option('geo_license_key', '');
        $domain = self::getDomainRoot();

        if (!empty($key) && self::isMasterKey($key)) {
            delete_option('geo_license_key');
            delete_option('geo_license_last_valid');
            delete_transient(self::CACHE_KEY);
            return ['status' => 'deactivated', 'message' => 'Licença Master removida deste site.'];
        }

        if (!empty($key)) {
            wp_remote_post(self::DEACTIVATE_URL, [
                'timeout'   => 15,
                'sslverify' => true,
                'body'      => ['license_key' => $key, 'domain' => $domain],
            ]);
        }

        delete_option('geo_license_key');
        delete_option('geo_license_last_valid');
        delete_transient(self::CACHE_KEY);

        return ['status' => 'deactivated', 'message' => 'Licença desativada neste site.'];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Domínio raiz
    // ─────────────────────────────────────────────────────────────────────────

    public static function getDomainRoot(string $url = ''): string {
        if (empty($url)) $url = get_site_url();

        $host = (string) parse_url($url, PHP_URL_HOST);
        $host = strtolower(trim($host));
        $host = preg_replace('/^www\./i', '', $host);
        $host = preg_replace('/:\d+$/', '', $host);

        $parts = explode('.', $host);
        $count = count($parts);

        $compound_tlds = [
            'com.br','org.br','net.br','edu.br','gov.br','mil.br',
            'co.uk','org.uk','me.uk','com.au','net.au','org.au',
            'co.nz','co.jp','com.mx','com.ar','com.pt',
        ];

        if ($count >= 3) {
            $last_two = $parts[$count-2] . '.' . $parts[$count-1];
            if (in_array($last_two, $compound_tlds, true)) {
                return implode('.', array_slice($parts, -3));
            }
        }

        return $count >= 2 ? implode('.', array_slice($parts, -2)) : $host;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Utilitários
    // ─────────────────────────────────────────────────────────────────────────

    public static function clearCache(): void {
        delete_transient(self::CACHE_KEY);
    }

    public static function planLabel(string $plan = ''): string {
        if ($plan === '') $plan = self::getPlan();
        $labels = [
            'trial'  => 'Trial Gratuito (5 artigos)',
            'basic'  => 'Basico — R$97/mes',
            'pro'    => 'Profissional — R$197/mes',
            'agency' => 'Agencia — R$397/mes',
            'master' => 'Master — Criador do Plugin',
        ];
        return $labels[$plan] ?? ucfirst($plan);
    }

    public static function planBadgeHtml(string $plan = ''): string {
        if ($plan === '') $plan = self::getPlan();
        $colors = [
            'trial'  => '#888',
            'basic'  => '#0073aa',
            'pro'    => '#8b5cf6',
            'agency' => '#e67e22',
            'master' => '#d4a017',
        ];
        $color = $colors[$plan] ?? '#888';
        return '<span style="background:' . $color . ';color:#fff;font-size:11px;padding:2px 8px;border-radius:10px;font-weight:600;">'
             . esc_html(strtoupper($plan)) . '</span>';
    }

    public static function lockedHtml(string $feature_label): string {
        if (self::getPlan() === 'master') return '';

        $trial_count = self::getTrialCount();
        $remaining   = max(0, self::TRIAL_LIMIT - $trial_count);

        return '<div class="wrap"><div style="max-width:600px;margin:40px auto;text-align:center;padding:40px;background:#fff;border:1px solid #ddd;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.08);">'
             . '<div style="font-size:56px;margin-bottom:16px;">🔒</div>'
             . '<h2 style="margin:0 0 12px;">' . esc_html($feature_label) . ' — Recurso Pago</h2>'
             . '<p style="color:#666;margin-bottom:8px;">Este recurso requer uma licença ativa.</p>'
             . '<p style="color:#666;margin-bottom:24px;">Trial atual: <strong>' . intval($trial_count) . '/' . self::TRIAL_LIMIT . ' artigos usados</strong> (' . $remaining . ' restantes)</p>'
             . self::plans_html()
             . '<a href="https://aiconteudo.com.br/plugin" target="_blank" class="button button-primary button-large" style="margin-top:20px;">Adquirir Licença</a>'
             . '</div></div>';
    }

    private static function plans_html(): string {
        $plans = [
            ['Basico',       'R$97/mes',  '1 site, todos os recursos',   '#0073aa'],
            ['Profissional', 'R$197/mes', '5 sites, todos os recursos',  '#8b5cf6'],
            ['Agencia',      'R$397/mes', 'Sites ilimitados, multisite', '#e67e22'],
        ];
        $html = '<div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-top:16px;">';
        foreach ($plans as $p) {
            $html .= '<div style="border:2px solid ' . $p[3] . ';border-radius:8px;padding:14px 18px;min-width:140px;">'
                   . '<div style="font-weight:700;color:' . $p[3] . ';font-size:15px;">' . esc_html($p[0]) . '</div>'
                   . '<div style="font-size:20px;font-weight:700;margin:4px 0;">' . esc_html($p[1]) . '</div>'
                   . '<div style="font-size:12px;color:#666;">' . esc_html($p[2]) . '</div>'
                   . '</div>';
        }
        $html .= '</div>';
        return $html;
    }
}
