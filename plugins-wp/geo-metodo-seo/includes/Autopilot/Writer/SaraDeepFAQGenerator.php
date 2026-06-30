<?php
declare(strict_types=1);

namespace GeoMetodoSEO\Autopilot\Writer;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\Autopilot\Shared\AutopilotLogger;
use GeoMetodoSEO\AI\AIManager;
use GeoMetodoSEO\AI\ProviderResolver;

/**
 * SaraDeepFAQGenerator — FAQ profundo (7-10 perguntas, 80-120 palavras cada).
 * 3 comuns + 4 técnicas + 3 polêmicas/surpreendentes.
 *
 * @since 1.0.0
 */
class SaraDeepFAQGenerator {

    private AIManager $ai;

    public function __construct() {
        $this->ai = new AIManager();
    }

    /**
     * Gera FAQ profundo via IA (GPT-4.1 ou Claude Sonnet 4.6).
     * @return array Array de ['question'=>string, 'answer'=>string, 'type'=>string]
     */
    public function generate(string $title, string $category, string $niche): array {
        // 1.0.0 CREDIT-SAFE FAQ FIX:
        // Quando FAQ via IA estiver desligado, o plugin DEVE gerar FAQ local obrigatório
        // e schema FAQPage sem gastar créditos. Nunca retornar vazio por economia.
        if (\GeoMetodoSEO\Autopilot\Shared\AutopilotInstaller::get('faq_generator_enabled', '0') !== '1') {
            $local = array_slice($this->fallback_faq($title, $niche), 0, 8);
            AutopilotLogger::log('writer', 'faq_local_generated', 'success',
                count($local) . ' perguntas FAQ locais geradas sem chamada de IA');
            return $local;
        }

        $prompt = $this->build_prompt($title, $category, $niche);

        $provider = ProviderResolver::for('faq');
        $provider_model = ProviderResolver::modelFor('faq', $provider);
        $response = $this->ai->generateText($prompt, $provider, $provider_model);

        if (!$response || $response->hasError() || trim((string)$response->getContent()) === '') {
            AutopilotLogger::log('writer', 'faq_fail', 'error',
                'FAQ IA falhou via ' . $provider . ': ' . ($response ? $response->getError() : 'sem resposta'));
            return array_slice($this->fallback_faq($title, $niche), 0, 8);
        }

        $raw = trim($response->getContent());
        $raw = preg_replace('/^```json\s*/i', '', $raw);
        $raw = preg_replace('/```\s*$/i', '', $raw);
        $raw = trim($raw);

        $faqs = json_decode($raw, true);

        // Tentar extrair JSON mesmo com texto antes/depois
        if (json_last_error() !== JSON_ERROR_NONE) {
            if (preg_match('/\[[\s\S]+\]/m', $raw, $m)) {
                $faqs = json_decode($m[0], true);
            }
        }

        if (!is_array($faqs) || empty($faqs)) {
            return array_slice($this->fallback_faq($title, $niche), 0, 8);
        }

        // Validar e normalizar
        $valid_faqs = [];
        foreach ($faqs as $faq) {
            if (empty($faq['question']) || empty($faq['answer'])) continue;
            $q = trim($faq['question']);
            $a = $this->limit_answer(trim($faq['answer']), 65);
            $word_count = str_word_count(strip_tags($a));
            if ($word_count < 20) continue; // muito curta

            $valid_faqs[] = [
                'question' => $q,
                'answer'   => $a,
                'type'     => $faq['type'] ?? 'comum',
            ];
        }

        if (count($valid_faqs) < 8) {
            // Combinar com fallback
            $valid_faqs = array_merge($valid_faqs, $this->fallback_faq($title, $niche));
            $valid_faqs = array_slice($valid_faqs, 0, 8);
        }

        $valid_faqs = array_slice($valid_faqs, 0, 8);
        AutopilotLogger::log('writer', 'faq_generated', 'success',
            count($valid_faqs) . ' perguntas FAQ geradas');

        return $valid_faqs;
    }

    /**
     * Renderiza FAQ em HTML com microdata Schema.org embutido (itemscope).
     */
    public function render_html(array $faqs): string {
        if (empty($faqs)) return '';

        $html = '<div class="sara-faq-section" itemscope itemtype="https://schema.org/FAQPage">';
        $html .= '<h2>Perguntas Frequentes (e Algumas que Ninguém Faz)</h2>';
        $html .= '<div class="sara-faq-list">';

        foreach ($faqs as $faq) {
            // 1.0.0: não exibir rótulos internos como "Técnica" ou "Não óbvia" no front-end.
            // Esses tipos são usados apenas para diversidade editorial e não devem parecer sobra de prompt.
            $type_badge = '';

            $html .= '<div class="sara-faq-item" itemscope itemprop="mainEntity" itemtype="https://schema.org/Question" style="margin-bottom:24px;padding:18px;background:transparent;border:1px solid rgba(148,163,184,.35);border-left:4px solid #6366f1;border-radius:8px;">';
            $html .= '<h3 itemprop="name" style="margin:0 0 12px;font-size:17px;">' . esc_html($faq['question']) . '</h3>';
            $html .= '<div itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer">';
            $html .= '<div itemprop="text" style="line-height:1.6;">' . wp_kses_post($faq['answer']) . '</div>';
            $html .= '</div></div>';
        }

        $html .= '</div></div>';
        return $html;
    }

    /**
     * Constrói schema FAQPage JSON-LD para inserir no <head>.
     */
    public function build_schema(array $faqs): string {
        $entities = [];
        foreach ($faqs as $faq) {
            $entities[] = [
                '@type' => 'Question',
                'name'  => $faq['question'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => strip_tags($faq['answer']),
                ],
            ];
        }
        return wp_json_encode([
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => $entities,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function build_prompt(string $title, string $category, string $niche): string {
        // 1.0.0: Forçar idioma no FAQ Generator também
        $language = \GeoMetodoSEO\Autopilot\Shared\AutopilotInstaller::get('site_language', 'pt-BR');
        $lang_map = [
            'pt-BR' => 'português brasileiro',
            'pt-PT' => 'português europeu',
            'en-US' => 'American English',
            'en-GB' => 'British English',
            'es-ES' => 'español',
            'es-MX' => 'español mexicano',
            'fr-FR' => 'français',
            'de-DE' => 'Deutsch',
            'it-IT' => 'italiano',
        ];
        $native_name = $lang_map[$language] ?? 'português brasileiro';

        return "🌐 IDIOMA OBRIGATÓRIO: Todas as perguntas e respostas em {$native_name} ({$language}).\n\n"
            . "Você é especialista sênior em {$niche} ({$category}) com 10 anos de experiência prática.\n\n"
            . "MISSÃO: Criar 8 perguntas FAQ objetivas sobre: \"{$title}\"\n\n"
            . "DISTRIBUIÇÃO OBRIGATÓRIA:\n"
            . "- 3 perguntas COMUNS (que pessoas pesquisam no Google)\n"
            . "- 3 perguntas TÉCNICAS (que poucos fazem mas profissionais querem saber)\n"
            . "- 2 perguntas POLÊMICAS ou SURPREENDENTES (ângulo contra-intuitivo)\n\n"
            . "REGRAS DE CADA RESPOSTA:\n"
            . "✅ 35-60 palavras (NÃO menos, NÃO mais)\n"
            . "✅ Use dados específicos SOMENTE quando estiverem confirmados no contexto; não invente números, percentuais, preços ou datas\n"
            . "✅ Tom direto e prático (sem floreios)\n"
            . "✅ Inclua exemplo concreto quando puder ser explicado sem inventar fonte, caso real ou estatística\n"
            . "✅ Termine com ação prática quando aplicável\n"
            . "✅ TUDO em {$native_name}\n\n"
            . "PROIBIDO:\n"
            . "❌ \"Imagine que...\", \"em resumo\", \"é importante notar\", \"vale ressaltar\"\n"
            . "❌ Anos, preços, estudos, fontes, rankings, especificações ou estatísticas inventadas\n"
            . "❌ Definições genéricas tipo Wikipédia\n"
            . "❌ Respostas curtas de 30-50 palavras\n"
            . "❌ Misturar idiomas\n\n"
            . "FORMATO DE RESPOSTA — JSON puro, sem markdown, sem ```:\n"
            . '[{"question":"...","answer":"...","type":"comum|tecnica|polemica"}]' . "\n\n"
            . "Retorne APENAS o JSON válido. Nada antes. Nada depois.";
    }

    /**
     * FAQ contextual — perguntas reais baseadas no título e nicho.
     * Substitui o antigo FAQ genérico de template que não respondia dúvidas reais.
     */
    private function limit_answer(string $answer, int $limit): string {
        $answer = trim(preg_replace('/\s+/u', ' ', wp_kses_post($answer)) ?? $answer);
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'’\-]*/u', wp_strip_all_tags($answer), $m, PREG_OFFSET_CAPTURE);
        $words = $m[0] ?? [];
        if (count($words) <= $limit) return $answer;
        $plain = wp_strip_all_tags($answer);
        $last = $words[$limit - 1][1] + strlen($words[$limit - 1][0]);
        return esc_html(rtrim(mb_substr($plain, 0, $last), " ,;:.-") . '.');
    }

    private function fallback_faq(string $title, string $niche): array {
        $kw  = $this->extract_keyword($title);
        $kw_cap = ucfirst($kw);
        $niche_lower = mb_strtolower($niche);
        $title_lower = mb_strtolower($title);

        $safe = function(string $q, string $a, string $type = 'comum') {
            return ['question' => $q, 'answer' => $this->limit_answer($a, 65), 'type' => $type];
        };

        // Detectar nicho para perguntas contextuais
        $is_smarthome  = str_contains($title_lower, 'casa inteligente') || str_contains($title_lower, 'automação') || str_contains($title_lower, 'smart home') || str_contains($niche_lower, 'casa');
        $is_mobile     = str_contains($title_lower, 'smartphone') || str_contains($title_lower, 'celular') || str_contains($title_lower, 'xiaomi') || str_contains($title_lower, 'android') || str_contains($niche_lower, 'smartphone');
        $is_decoracao  = str_contains($title_lower, 'decoraç') || str_contains($title_lower, 'ambiente') || str_contains($title_lower, 'sala') || str_contains($title_lower, 'quarto') || str_contains($niche_lower, 'decoraç');
        $is_culinaria  = str_contains($title_lower, 'receita') || str_contains($title_lower, 'cozinha') || str_contains($title_lower, 'comer') || str_contains($title_lower, 'alimento') || str_contains($niche_lower, 'culinária');
        $is_games      = str_contains($title_lower, 'game') || str_contains($title_lower, 'jogo') || str_contains($title_lower, 'gamer') || str_contains($niche_lower, 'games');
        $is_beleza     = str_contains($title_lower, 'beleza') || str_contains($title_lower, 'pele') || str_contains($title_lower, 'cabelo') || str_contains($title_lower, 'maquiagem') || str_contains($niche_lower, 'beleza');
        $is_pets       = str_contains($title_lower, 'cachorro') || str_contains($title_lower, 'gato') || str_contains($title_lower, 'pet') || str_contains($title_lower, 'animal') || str_contains($niche_lower, 'pets');
        $is_viagem     = str_contains($title_lower, 'viagem') || str_contains($title_lower, 'destino') || str_contains($title_lower, 'hotel') || str_contains($title_lower, 'turismo') || str_contains($niche_lower, 'viagem');
        $is_saude      = str_contains($title_lower, 'saúde') || str_contains($title_lower, 'exercício') || str_contains($title_lower, 'dieta') || str_contains($title_lower, 'médico') || str_contains($niche_lower, 'saúde');
        $is_financas   = str_contains($title_lower, 'investimento') || str_contains($title_lower, 'finanças') || str_contains($title_lower, 'dinheiro') || str_contains($title_lower, 'renda') || str_contains($niche_lower, 'finanças');
        $is_seo        = str_contains($title_lower, 'seo') || str_contains($title_lower, 'rankeamento') || str_contains($niche_lower, 'seo');

        if ($is_smarthome) {
            return [
                $safe("Quanto custa montar uma casa inteligente básica?", "Uma configuração inicial com lâmpadas inteligentes, tomadas e um assistente virtual como Alexa ou Google Nest pode custar entre R\$ 500 e R\$ 2.000, dependendo da quantidade de dispositivos. O investimento cresce conforme você adiciona câmeras, fechaduras e termostatos. O ideal é começar pequeno e expandir gradualmente.", 'comum'),
                $safe("Qual é o melhor ecossistema: Amazon Alexa, Google Home ou Apple HomeKit?", "Depende do seu smartphone. Usuários de Android têm melhor integração com o Google Home. Usuários de iPhone preferem o Apple HomeKit pela privacidade e integração nativa. Amazon Alexa é o mais compatível com dispositivos de terceiros e tende a ter preços menores. Nenhum é universalmente melhor — o critério é compatibilidade com o que você já usa.", 'tecnica'),
                $safe("Dispositivos de casa inteligente funcionam sem internet?", "A maioria depende de internet para comandos remotos e assistentes de voz. Porém, alguns dispositivos funcionam localmente via Bluetooth ou Zigbee quando a internet cai — como certas lâmpadas e fechaduras. Verifique antes de comprar se o dispositivo suporta controle local.", 'tecnica'),
                $safe("{$kw_cap} é complicado de instalar para quem não é técnico?", "Dispositivos básicos como lâmpadas e tomadas inteligentes são plug-and-play — basta conectar e configurar pelo app em minutos. Sistemas mais complexos como câmeras externas ou fechaduras podem exigir instalação profissional. Para iniciantes, começar com lâmpadas inteligentes é o caminho mais simples.", 'comum'),
                $safe("A casa inteligente consome mais energia elétrica?", "Não necessariamente — na maioria dos casos, ela reduz o consumo. Termostatos inteligentes evitam desperdício de ar-condicionado, e sistemas de iluminação só acendem quando há presença. Com automação bem configurada, a economia de energia pode chegar a 30% dependendo do padrão de uso.", 'polemica'),
                $safe("Dispositivos de casa inteligente são seguros contra hackers?", "O risco existe, mas é gerenciável. As maiores vulnerabilidades são senhas padrão não alteradas e firmware desatualizado. Para se proteger: use senhas únicas e fortes em cada dispositivo, ative autenticação em dois fatores quando disponível e mantenha os apps e firmwares sempre atualizados.", 'polemica'),
                $safe("Vale a pena investir em casa inteligente em apartamento alugado?", "Sim, com cuidado na escolha. Lâmpadas, tomadas e assistentes virtuais não alteram a estrutura do imóvel e podem ser levados ao mudar. Evite instalar fechaduras inteligentes fixas ou câmeras externas sem autorização do proprietário.", 'comum'),
                $safe("Qual a diferença entre Zigbee, Z-Wave e Wi-Fi em dispositivos inteligentes?", "Wi-Fi é o mais simples de configurar, mas consome mais banda. Zigbee e Z-Wave criam redes mesh com consumo de energia muito menor — ideais para muitos dispositivos. Zigbee é mais barato e comum; Z-Wave tem menor interferência. Para iniciantes, Wi-Fi é suficiente.", 'tecnica'),
            ];
        }

        if ($is_mobile) {
            return [
                $safe("Quanto tempo dura a bateria de {$kw_cap} no uso real?", "A duração de bateria varia conforme o uso: quem usa muito redes sociais, câmera e jogos pode consumir uma carga em 6-8 horas. Em uso moderado, a maioria dos smartphones modernos dura 1-2 dias. A capacidade em mAh indica o potencial, mas o software de gerenciamento faz tanta diferença quanto o hardware.", 'comum'),
                $safe("{$kw_cap} recebe atualizações de software por quanto tempo?", "Fabricantes como Samsung prometem até 7 anos de atualizações nos top de linha. Xiaomi, Motorola e outros da linha intermediária costumam oferecer 2-3 anos de Android e 4 anos de segurança. Verifique a política oficial do fabricante antes de comprar.", 'tecnica'),
                $safe("{$kw_cap} é bom para jogos mobile?", "Depende do processador, memória RAM e taxa de atualização da tela. Para jogos casuais, qualquer intermediário serve. Para games pesados, procure pelo menos 8 GB RAM, processador da série Snapdragon 7 ou Dimensity 8000 ou superior, e tela com 90 Hz ou mais.", 'comum'),
                $safe("Como saber se um smartphone é nacional ou importado?", "Smartphones nacionais têm o selo ANATEL e nota fiscal brasileira. Importados não têm garantia legal no Brasil e podem ter bandas de rede incompatíveis com operadoras locais. Comprar importado pode parecer mais barato, mas a assistência técnica e a garantia não cobrem produtos sem certificação nacional.", 'polemica'),
                $safe("Vale a pena comprar smartphone na promoção da Black Friday?", "Sim, mas com critério. Pesquise o preço histórico no Buscapé ou Google Shopping antes da data — algumas promoções inflam o preço original. Priorize aparelhos do modelo do ano anterior em liquidação ou linhas com suporte garantido por 2+ anos.", 'polemica'),
                $safe("Qual é a câmera mais importante em um smartphone: resolução ou abertura?", "A abertura (f/1.6, f/1.8) é mais importante que megapixels para fotos com pouca luz. Uma câmera de 12 MP com f/1.8 tira fotos melhores à noite do que uma de 108 MP com f/2.2. Já para fotos com luz abundante, os megapixels importam mais para ampliar imagens.", 'tecnica'),
                $safe("{$kw_cap} tem suporte a 5G?", "Verifique as especificações técnicas e confirme as bandas 5G suportadas. No Brasil, as principais são n78 e n1. Não basta o aparelho suportar 5G — precisa das bandas corretas para cada operadora. Consulte o site da Anatel para verificar quais bandas cada operadora usa em sua cidade.", 'tecnica'),
                $safe("Qual a diferença entre os processadores Snapdragon, MediaTek e Exynos?", "Snapdragon (Qualcomm) é o mais usado em flagships — destaque em desempenho e eficiência. MediaTek Dimensity cresceu muito e hoje rivaliza com preço menor. Exynos (Samsung) é usado em alguns mercados com desempenho variável. Para uso cotidiano, os três funcionam bem nos modelos intermediários e superiores.", 'tecnica'),
            ];
        }

        if ($is_decoracao) {
            return [
                $safe("Por onde começar a decorar um ambiente do zero?", "Comece definindo a paleta de cores — no máximo 3 cores principais. Depois escolha o móvel principal do cômodo (sofá na sala, cama no quarto) e projete o restante em torno dele. Só depois adicione elementos decorativos como quadros, plantas e objetos. Essa ordem evita compras desnecessárias e ambientes sobrecarregados.", 'comum'),
                $safe("Quanto custa decorar um quarto ou sala com orçamento limitado?", "Com R\$ 300 a R\$ 800 é possível transformar um ambiente com tinta de parede, almofadas novas, um tapete e algumas plantas. A chave é investir em poucos elementos de impacto em vez de muitos itens pequenos. Lojas como Tok&Stok, Leroy Merlin e até brechós têm ótimas opções custo-benefício.", 'comum'),
                $safe("{$kw_cap} funciona em apartamentos pequenos?", "Sim, e em alguns casos funciona melhor. Ambientes pequenos se beneficiam de cores claras nas paredes, espelhos estratégicos, móveis multifuncionais e iluminação bem posicionada. Evite móveis grandes demais — prefira peças com pés aparentes, que criam sensação de amplitude.", 'tecnica'),
                $safe("Quais erros mais comuns ao escolher móveis e decoração?", "Os erros mais frequentes são: comprar sem medir o espaço, misturar muitos estilos sem coerência, ignorar a iluminação natural, escolher cores muito escuras em ambientes pequenos e comprar tudo de uma vez sem planejamento. Decorar por etapas é mais econômico e resulta em ambientes mais harmoniosos.", 'polemica'),
                $safe("Como escolher tapete do tamanho certo para cada ambiente?", "Na sala, o tapete ideal tem ao menos as patas frontais do sofá apoiadas nele. No quarto de casal, o tapete deve ultrapassar a cama em pelo menos 40-50 cm de cada lado. Tapetes pequenos demais são o erro mais comum — eles 'encolhem' o ambiente visualmente.", 'tecnica'),
                $safe("Plantas combinam com qualquer estilo de decoração?", "Sim — plantas têm a capacidade de suavizar ambientes modernos e aquecer espaços minimalistas. Para ambientes com pouca luz, opte por espada-de-são-jorge, zamioculca ou jiboia. Em ambientes com boa iluminação, monsteras e filodendros crescem bem. Vasos de cerâmica ou concreto funcionam em quase todos os estilos.", 'comum'),
                $safe("Como usar luz artificial para valorizar a decoração?", "A luz fria (acima de 4.000K) é ideal para escritórios e cozinhas — aumenta o foco. A luz quente (2.700K a 3.000K) cria atmosfera aconchegante em salas e quartos. Combine pelo menos dois tipos de iluminação por ambiente: luz de teto difusa + luminárias ou spots direcionados para criar profundidade.", 'tecnica'),
                $safe("Vale a pena contratar um decorador de interiores?", "Para projetos maiores ou reformas, sim — um decorador evita erros caros de escala, proporção e materiais. Para mudanças menores, um bom planejamento próprio com referências do Pinterest e medições precisas pode dar ótimos resultados. O custo de um decorador varia de R\$ 150 a R\$ 500 por hora dependendo da região.", 'polemica'),
            ];
        }

        if ($is_culinaria) {
            return [
                $safe("Dá para substituir ingredientes difíceis de encontrar em {$kw_cap}?", "Na maioria das receitas, sim. Manteiga pode ser substituída por óleo de coco ou margarina. Farinha de trigo tem alternativas como farinha de arroz ou amêndoa para versões sem glúten. Creme de leite pode ser trocado por leite de coco. A textura e o sabor mudam levemente, mas o resultado ainda é bom.", 'comum'),
                $safe("Qual é o erro mais comum ao preparar {$kw_cap}?", "O erro mais frequente é não ler a receita completa antes de começar. Isso leva a surpresas no meio do preparo — ingrediente faltando, tempo de geladeira não previsto, ou técnica desconhecida. Ler tudo e separar os ingredientes antes (mise en place) aumenta muito a chance de sucesso.", 'tecnica'),
                $safe("{$kw_cap} pode ser preparado com antecedência?", "Depende do tipo de receita. Pratos assados e ensopados geralmente ficam mais saborosos no dia seguinte, pois os temperos se integram melhor. Massas e fritos devem ser preparados na hora. Sobremesas cremosas costumam aguardar bem na geladeira por até 2-3 dias.", 'comum'),
                $safe("Como saber se os ingredientes estão frescos para a receita?", "Para carnes: verifique a cor, o cheiro e a data de validade. Para vegetais: folhas firmes e cor viva indicam frescor. Para ovos: coloque em água — ovos frescos afundam, ovos velhos flutuam. Ingredientes frescos fazem diferença direta no sabor final.", 'tecnica'),
                $safe("Como adaptar {$kw_cap} para dietas específicas (vegana, sem glúten, low carb)?", "Para versão vegana: substitua ovos por linhaça hidratada ou aquafaba, e laticínios por versões vegetais. Para sem glúten: use farinhas alternativas como arroz, amêndoa ou tapioca. Para low carb: reduza farinhas e açúcares, priorizando ingredientes integrais e proteínas.", 'tecnica'),
                $safe("Quais temperos realçam mais o sabor em pratos como {$kw_cap}?", "Ervas frescas como salsinha, coentro e manjericão adicionadas no final da cocção preservam o aroma. Alho e cebola fritos na base constroem sabor profundo. Sal grosso e pimenta-do-reino moída na hora fazem mais diferença do que parece. Limão espremido no final realça todos os outros sabores.", 'comum'),
                $safe("Qual é o tempo real de preparo de {$kw_cap}?", "O tempo informado nas receitas costuma ser o tempo ativo de preparo — sem contar o tempo de forno, geladeira ou repouso. Sempre some os tempos passivos para planejar bem. Uma receita de \"30 minutos\" pode levar 1h30 no total se precisar de 1 hora de forno.", 'polemica'),
                $safe("Vale a pena investir em utensílios melhores para cozinhar?", "Para itens de uso diário, sim. Uma boa faca de chef, uma tábua de madeira resistente e uma frigideira antiaderente de qualidade fazem diferença real no preparo. Para utensílios específicos, avalie a frequência de uso — comprar um processador para usar uma vez por mês raramente compensa.", 'polemica'),
            ];
        }

        if ($is_games) {
            return [
                $safe("Vale mais a pena PC ou console para jogar {$kw_cap}?", "PC oferece mais flexibilidade — upgrade de componentes, mods, preços menores nos jogos e teclado e mouse. Console oferece exclusivos, facilidade de uso e experiência plug-and-play. Para jogadores casuais, console é mais simples. Para quem quer mais performance e personalização, PC compensa a longo prazo.", 'polemica'),
                $safe("Quais são as configurações mínimas para rodar {$kw_cap} bem?", "Verifique sempre os requisitos mínimos e recomendados na página oficial do jogo na Steam, Epic Games ou site do desenvolvedor. Para 1080p/60fps em gráficos médios, em geral uma GPU equivalente a RTX 3060 ou RX 6600 é suficiente para a maioria dos lançamentos de 2024-2026.", 'tecnica'),
                $safe("{$kw_cap} tem suporte a português brasileiro?", "Muitos jogos lançados no Brasil oferecem localização completa em PT-BR — legendas e dublagem. Verifique na página da loja (Steam, PS Store, Xbox) nas configurações de idioma antes de comprar. Jogos sem PT-BR costumam ter tradução da comunidade disponível no Nexus Mods ou fóruns dedicados.", 'comum'),
                $safe("Como evitar lag e quedas de FPS ao jogar online?", "Use cabo de rede em vez de Wi-Fi sempre que possível — reduz latência em até 30-50ms. Feche programas em segundo plano que consomem banda (streaming, downloads). Configure o jogo para priorizar desempenho nas opções gráficas. Servidores da região sul/sudeste do Brasil costumam ter melhor ping.", 'tecnica'),
                $safe("Vale a pena comprar {$kw_cap} no lançamento ou esperar promoção?", "Se você quer a experiência sem spoilers e aproveitar o hype da comunidade, lançamento faz sentido. Se o orçamento é limitado, espere — a maioria dos jogos cai 30-60% de preço em 6-12 meses, especialmente na Steam. Jogos de serviço ao vivo (live service) tendem a cair mais rápido.", 'polemica'),
                $safe("Como proteger conta de jogos de hacks e roubos?", "Ative autenticação em dois fatores (2FA) — Steam Guard, PlayStation Network Authenticator, Xbox Authenticator. Nunca compartilhe credenciais nem clique em links de supostos brindes. Phishing é o método mais comum de roubo de contas. Revise os dispositivos autorizados periodicamente.", 'tecnica'),
                $safe("Periféricos fazem diferença real no desempenho nos jogos?", "Para jogos competitivos, sim. Mouse com alta taxa de polling (1000Hz+), teclado mecânico e headset com microfone direcional fazem diferença em FPS e battle royales. Para jogos de ação-aventura e RPG, o impacto é menor — o prazer é mais na experiência do que na vantagem competitiva.", 'comum'),
                $safe("O que fazer quando {$kw_cap} trava ou fecha sozinho?", "Primeiro, verifique a integridade dos arquivos na plataforma (Steam > Propriedades > Arquivos locais > Verificar). Atualize drivers de GPU e DirectX. Desative overlays de terceiros (Discord, GeForce Experience). Se persistir, verifique temperatura da GPU e RAM — superaquecimento causa crashes frequentes.", 'tecnica'),
            ];
        }

        if ($is_beleza) {
            return [
                $safe("Qual é a ordem correta de aplicar produtos de skincare?", "A ordem correta é: limpeza → tônico → sérum → hidratante → protetor solar (de manhã). À noite, substitua o protetor por um tratamento específico (retinol, ácido glicólico). Produtos de textura mais leve sempre antes dos mais densos — isso garante que cada produto seja absorvido corretamente.", 'tecnica'),
                $safe("Como escolher o protetor solar ideal para o rosto?", "Considere o fototipo (tom de pele), o FPS e a textura. Peles oleosas se dão melhor com texturas fluidas e oil-free. Peles secas preferem fórmulas com hidratantes como ácido hialurônico. Para uso diário urbano, FPS 30 é suficiente; praia e esportes pedem FPS 50+. Reaplique a cada 2 horas em exposição direta.", 'tecnica'),
                $safe("{$kw_cap} funciona para todos os tipos de pele?", "A maioria dos produtos tem formulações específicas por tipo de pele — oleosa, seca, mista ou sensível. Usar um produto inadequado para o seu tipo pode piorar oleosidade, causar ressecamento ou irritação. Sempre leia a indicação na embalagem e, em caso de dúvida, faça um patch test antes de aplicar no rosto inteiro.", 'comum'),
                $safe("Com que frequência deve-se fazer esfoliação?", "Esfoliação química (ácidos como AHA/BHA) pode ser feita 2-3 vezes por semana para peles normais ou oleosas. Peles sensíveis toleram 1 vez por semana. Esfoliação física (scrubs) deve ser usada com menos frequência — 1-2 vezes por semana máximo. Excesso de esfoliação danifica a barreira cutânea.", 'comum'),
                $safe("Qual a diferença entre BB cream, CC cream e base?", "BB cream hidrata, uniformiza e oferece proteção leve — ideal para uso casual. CC cream foca em corrigir manchas e tom irregular com cobertura média. Base oferece cobertura completa e durabilidade — indicada para ocasiões especiais ou peles com imperfeições mais acentuadas. A escolha depende da cobertura desejada.", 'tecnica'),
                $safe("Como cuidar do cabelo sem danificá-lo?", "Evite calor excessivo sem protetor térmico — spray ou creme protetor é obrigatório antes do secador ou chapinha. Reduza a frequência de lavagem para 2-3 vezes por semana se possível, pois lavar diariamente remove os óleos naturais. Hidratação mensal e corte a cada 3 meses previnem pontas duplas.", 'comum'),
                $safe("Produtos de beleza mais caros são sempre melhores?", "Não necessariamente. Muitos ingredientes-chave como vitamina C, retinol e ácido hialurônico funcionam bem em formulações acessíveis. O que importa é a concentração do ativo e a estabilidade da fórmula, não o preço. Marcas farmacêuticas como La Roche-Posay e CeraVe oferecem ótimo custo-benefício.", 'polemica'),
                $safe("O que é rotina de skincare minimalista e funciona?", "A rotina minimalista usa apenas 3-4 produtos essenciais: limpador, hidratante e protetor solar (de manhã). À noite, adiciona um tratamento específico. Essa abordagem reduz o risco de irritação por excesso de produtos e facilita a consistência — que é o que realmente traz resultado.", 'polemica'),
            ];
        }

        if ($is_pets) {
            return [
                $safe("Qual é a ração mais indicada para {$kw_cap}?", "Prefira rações com proteína animal como primeiro ingrediente na lista — frango, peixe ou carne bovina. Evite rações com corantes artificiais, conservantes BHA/BHT e farinha de subprodutos como ingrediente principal. Para definir a melhor opção para cada pet, consulte um veterinário nutrólogo — as necessidades variam por raça, idade e peso.", 'tecnica'),
                $safe("Com que frequência um pet deve ir ao veterinário?", "Filhotes precisam de consultas mensais nos primeiros meses para vacinas e vermífugo. Adultos saudáveis devem ir ao menos 1-2 vezes por ano para check-up preventivo. Pets com mais de 7 anos precisam de exames semestrais — doenças crônicas são mais comuns e a detecção precoce faz grande diferença.", 'comum'),
                $safe("Como saber se meu pet está com algum problema de saúde?", "Sinais de alerta incluem: perda de apetite por mais de 24h, letargia, vômitos ou diarreia frequentes, alterações na urina (cor, frequência, esforço), coceira excessiva, perda de pelo em manchas e mudanças bruscas de comportamento. Em qualquer desses casos, consulte o veterinário — não espere piorar.", 'tecnica'),
                $safe("{$kw_cap} se adapta bem a apartamentos?", "Depende da raça e do porte. Raças menores e mais calmas se adaptam melhor a espaços menores. O que define a adaptação não é só o tamanho do apartamento, mas a quantidade de exercício diário e a estimulação mental — passeios regulares e brincadeiras são fundamentais independente do espaço.", 'comum'),
                $safe("Qual é o custo mensal médio para manter um pet no Brasil?", "Para cães de porte médio: ração premium (R\$ 150-300), consultas veterinárias rateadas (R\$ 50-100/mês), petiscos e acessórios (R\$ 50-100). Para gatos: custos similares, com vantagem na dispensa de passeios. Guarde uma reserva de emergência — cirurgias e internações podem custar R\$ 2.000 a R\$ 10.000.", 'polemica'),
                $safe("Como apresentar um novo pet para outros animais da casa?", "Faça a apresentação de forma gradual — primeiro pelo cheiro (itens com o odor do novo animal). Depois, apresentações visuais com separação física (grade ou porta). Só permita contato direto quando os dois estiverem calmos. O processo pode levar de dias a semanas — forçar o encontro causa traumas e agressividade.", 'tecnica'),
                $safe("Vale a pena contratar plano de saúde para pet?", "Depende da raça e da idade. Para raças com predisposição a doenças genéticas ou para pets acima de 5 anos, planos que cobrem exames e internações podem compensar. Compare cobertura, carência e custo mensal com os gastos veterinários médios da raça antes de decidir.", 'polemica'),
                $safe("Como escolher o petisco certo sem prejudicar a saúde do pet?", "Petiscos devem representar no máximo 10% da dieta diária — excesso causa obesidade. Prefira petiscos com ingrediente natural como principal componente. Evite alimentos humanos como petisco — uva, cebola, chocolate e alho são tóxicos para cães e gatos. Petiscos funcionais (dental, articular) têm benefício adicional.", 'comum'),
            ];
        }

        if ($is_viagem) {
            return [
                $safe("Qual é o melhor período para visitar {$kw_cap}?", "O melhor período varia por destino. Em geral, a baixa temporada oferece preços menores e menos turistas, mas pode coincidir com clima menos favorável. A alta temporada garante clima ideal mas cobra mais. Pesquise o clima médio mês a mês no destino específico para equilibrar custo e experiência.", 'comum'),
                $safe("Quanto custa uma viagem para {$kw_cap} partindo do Brasil?", "O custo total depende de passagem, hospedagem, alimentação e atrações. Destinos internacionais europeus costumam exigir orçamento de R\$ 15.000 a R\$ 30.000 por pessoa para 15 dias. Destinos nacionais variam de R\$ 2.000 a R\$ 8.000 por pessoa para viagens de 7 dias, dependendo da região e categoria de hospedagem.", 'comum'),
                $safe("Como economizar na compra de passagens aéreas?", "Compre com 6-8 semanas de antecedência para voos domésticos e 3-5 meses para internacionais. Use ferramentas como Google Flights e Skyscanner para monitorar preços e criar alertas. Voos com conexão custam em média 30-50% menos que diretos. Terças e quartas costumam ter tarifas menores.", 'tecnica'),
                $safe("Quais documentos são obrigatórios para viagem internacional?", "Passaporte válido com pelo menos 6 meses além da data de retorno é obrigatório para quase todos os países. Verifique a necessidade de visto — o site do Ministério das Relações Exteriores do Brasil tem informações atualizadas por destino. Para alguns países, comprovante de hospedagem e passagem de volta também são exigidos.", 'tecnica'),
                $safe("Seguro viagem é obrigatório ou apenas recomendado?", "É obrigatório para entrada na Europa (Área Schengen) — cobertura mínima de € 30.000 para despesas médicas. Para outros destinos, não é obrigatório mas é fortemente recomendado. Uma internação hospitalar nos EUA pode custar US\$ 10.000 por dia — o seguro custa a partir de R\$ 50 por dia e cobre emergências médicas, cancelamentos e bagagem.", 'polemica'),
                $safe("Como evitar problemas com bagagem em viagens aéreas?", "Verifique a franquia de bagagem da companhia aérea antes de despachar — regras variam por empresa e destino. Nunca coloque documentos, remédios e itens essenciais na mala despachada. Fotografe a bagagem antes de despachar para facilitar eventual reclamação. Identifique a mala externa e internamente com nome e contato.", 'tecnica'),
                $safe("Vale a pena comprar pacote de viagem ou montar individualmente?", "Pacotes compensam quando incluem traslados, guias e hospedagens difíceis de contratar individualmente. Para destinos populares com boa infraestrutura, montar individualmente costuma ser mais barato e flexível. Compare os dois cenários no mesmo período — a diferença pode variar de R\$ 500 a R\$ 3.000 dependendo do destino.", 'polemica'),
                $safe("Como se proteger de golpes e roubos em viagens?", "Evite exibir celulares caros, câmeras e joias em locais movimentados. Guarde documentos originais no cofre do hotel e ande com cópias. Use carteiras antifurto RFID em países com alto índice de clonagem. Em casos de emergência, anote o número do consulado brasileiro no destino antes de partir.", 'tecnica'),
            ];
        }

        if ($is_saude) {
            return [
                $safe("Quantas vezes por semana devo praticar {$kw_cap} para ver resultados?", "Para iniciantes, 3 vezes por semana com pelo menos 1 dia de descanso entre sessões é o mínimo para adaptação sem risco de lesão. Praticantes intermediários se beneficiam de 4-5 sessões semanais. A consistência ao longo do tempo importa mais do que a intensidade pontual — resultados aparecem em 4-8 semanas de prática regular.", 'comum'),
                $safe("Dá para fazer {$kw_cap} sem academia?", "Sim, para a maioria dos objetivos — perda de peso, condicionamento e flexibilidade podem ser desenvolvidos com treinos em casa ou ao ar livre. A academia oferece equipamentos para hipertrofia muscular e modalidades específicas. Apps como Nike Training Club e YouTube oferecem programas gratuitos para treino domiciliar.", 'comum'),
                $safe("Como evitar lesões ao praticar {$kw_cap}?", "Sempre aqueça por 5-10 minutos antes da atividade principal. Respeite os limites do seu corpo — dor aguda é sinal de parar. Progrida gradualmente — não aumente carga ou volume mais de 10% por semana. Alongue após o treino. Descanse adequadamente — é no descanso que o corpo se recupera e melhora.", 'tecnica'),
                $safe("Alimentação influencia no desempenho em {$kw_cap}?", "Diretamente. Carboidratos fornecem energia para atividades de alta intensidade. Proteínas (1,6-2,2g por kg de peso) são essenciais para recuperação muscular. Hidratação adequada — pelo menos 500ml antes e durante atividades longas — previne queda de desempenho. Evite treinar em jejum intenso se for iniciante.", 'tecnica'),
                $safe("Quando devo procurar um médico antes de iniciar {$kw_cap}?", "Sempre que tiver histórico de problemas cardíacos, pressão alta, diabetes ou lesões antigas. Para pessoas acima de 40 anos sedentárias, um check-up com exames básicos (eletrocardiograma, hemograma) é recomendado antes de iniciar atividades intensas. Sintomas como dor no peito, falta de ar ou tontura durante exercício pedem avaliação imediata.", 'polemica'),
                $safe("Quantas horas de sono são necessárias para quem pratica exercícios?", "Adultos precisam de 7-9 horas por noite. Para quem pratica atividades intensas, o sono de qualidade é ainda mais importante — é durante o sono profundo que o hormônio do crescimento é liberado e os músculos se recuperam. Menos de 6 horas cronicamente prejudica desempenho, recuperação e ganho muscular.", 'tecnica'),
                $safe("Suplementos são necessários para quem pratica {$kw_cap}?", "Para a maioria das pessoas com alimentação equilibrada, não. Proteína de whey pode ajudar a atingir a ingestão proteica diária quando a alimentação não é suficiente. Creatina tem evidência científica sólida para ganho de força e massa em treinos de resistência. Evite suplementos sem orientação profissional — excesso pode sobrecarregar fígado e rins.", 'polemica'),
                $safe("Como manter a motivação para continuar praticando {$kw_cap}?", "Estabeleça metas pequenas e mensuráveis — não 'emagrecer', mas 'perder 2 kg em 30 dias'. Treine com um parceiro ou em grupo — comprometimento social aumenta consistência. Varie a rotina a cada 4-6 semanas para evitar platôs e tédio. Registre o progresso — ver evolução é um dos maiores motivadores.", 'comum'),
            ];
        }

        if ($is_financas) {
            return [
                $safe("Qual é a diferença entre {$kw_cap} e outros tipos de investimento?", "Cada investimento tem perfil de risco, liquidez e rentabilidade diferentes. Renda fixa (Tesouro Direto, CDB, LCI) oferece previsibilidade com risco baixo. Renda variável (ações, FIIs) tem maior potencial de retorno com volatilidade maior. Diversificar entre as categorias reduz risco sem sacrificar rentabilidade no longo prazo.", 'tecnica'),
                $safe("Quanto devo ter de reserva de emergência antes de investir?", "O ideal é ter entre 3 e 6 meses de despesas mensais em um investimento de alta liquidez e baixo risco — como Tesouro Selic ou CDB com liquidez diária. Só comece a investir em renda variável ou ativos de maior prazo depois que essa reserva estiver completa. Sem ela, você pode ser forçado a resgatar investimentos no pior momento.", 'comum'),
                $safe("{$kw_cap} é indicado para iniciantes?", "Depende do tipo e do valor inicial. Tesouro Direto e fundos de renda fixa são bons pontos de entrada — baixo risco e aplicações a partir de R\$ 30. Ações e criptomoedas exigem mais conhecimento e tolerância a variações. Antes de qualquer investimento, entenda o que você está comprando e quais são os riscos reais.", 'comum'),
                $safe("Qual é o imposto de renda sobre ganhos em {$kw_cap}?", "Varia por tipo de investimento. Renda fixa: IR de 15% a 22,5% sobre o rendimento (tabela regressiva — quanto maior o prazo, menor a alíquota). Ações: isenção até R\$ 20.000/mês em vendas; acima disso, 15% sobre o lucro. LCI, LCA e poupança: isentos de IR para pessoa física. Consulte a Receita Federal para atualizações.", 'tecnica'),
                $safe("Como declarar {$kw_cap} no Imposto de Renda?", "Todos os investimentos com saldo acima de R\$ 140 devem ser declarados na ficha 'Bens e Direitos'. Rendimentos tributáveis (CDB, fundos) vêm no informe de rendimentos da instituição financeira. Rendimentos isentos (poupança, LCI, LCA) vão na ficha 'Rendimentos Isentos'. Erros na declaração podem gerar malha fina.", 'tecnica'),
                $safe("Vale mais a pena quitar dívidas ou investir?", "Se a dívida tem juros acima de 1% ao mês (cartão de crédito, cheque especial), quitá-la é sempre a melhor 'rentabilidade' — nenhum investimento conservador supera 12% ao ano de forma segura. Para dívidas com juros abaixo de 0,8% ao mês (financiamento imobiliário, crédito consignado), manter e investir ao mesmo tempo pode fazer sentido.", 'polemica'),
                $safe("Quanto tempo leva para ver resultado em {$kw_cap}?", "Juros compostos levam tempo para mostrar impacto real. Com aporte mensal de R\$ 500 a 0,8% ao mês, em 10 anos o valor acumulado supera R\$ 115.000. Os primeiros anos parecem lentos — a aceleração vem depois. Consistência nos aportes importa mais do que o valor individual de cada aplicação.", 'polemica'),
                $safe("Qual é o risco real de perder dinheiro em {$kw_cap}?", "Em renda fixa com garantia do FGC (Fundo Garantidor de Créditos) até R\$ 250.000 por CPF por instituição, o risco de perda total é mínimo. Em renda variável, o preço pode cair temporariamente — mas no longo prazo (10+ anos), o mercado historicamente se recupera. O maior risco é resgatar no momento de queda.", 'tecnica'),
            ];
        }

        if ($is_seo) {
            return [
                $safe("Quanto tempo leva para uma página ranquear no Google?", "Para palavras-chave novas em sites sem histórico, de 3 a 12 meses. Para sites com autoridade estabelecida e bom conteúdo, 4-8 semanas é possível para palavras-chave menos competitivas. Páginas que atualizam conteúdo existente ranqueiam mais rápido que páginas completamente novas. Consistência de publicação acelera o processo.", 'tecnica'),
                $safe("Qual é a diferença entre SEO on-page e off-page?", "SEO on-page são os fatores que você controla diretamente — conteúdo, meta tags, estrutura de headings, velocidade da página, links internos e experiência do usuário. SEO off-page envolve fatores externos — principalmente backlinks de outros sites. Ambos são necessários; on-page é pré-requisito para off-page fazer efeito.", 'tecnica'),
                $safe("Quantas palavras um artigo precisa ter para ranquear bem?", "Não existe um número mágico — o Google prioriza relevância, não tamanho. Artigos de 1.500-2.500 palavras tendem a cobrir o tema com mais profundidade e a ganhar mais backlinks naturais. Para queries informacionais, profundidade importa. Para transacionais (comprar X), páginas mais curtas e diretas funcionam melhor.", 'polemica'),
                $safe("Links externos prejudicam o SEO?", "Links externos para sites de autoridade (Wikipedia, governo, grandes publicações) geralmente ajudam — mostram que o conteúdo é bem referenciado. Links para sites de baixa qualidade ou spam podem prejudicar. Use rel=\"nofollow\" quando não quiser passar autoridade para um link externo.", 'polemica'),
                $safe("Schema markup realmente melhora o ranqueamento?", "Schema não é fator direto de ranqueamento — o Google confirmou isso. Mas aumenta a visibilidade nos resultados com rich snippets (estrelas de avaliação, FAQ, receitas, eventos), o que melhora o CTR. Uma página com rich snippet pode ter 20-30% mais cliques mesmo em posição inferior a outra sem schema.", 'tecnica'),
                $safe("Com que frequência devo publicar conteúdo para melhorar o SEO?", "Qualidade supera frequência. Um artigo profundo por semana traz mais resultado que cinco artigos superficiais. Para sites novos, consistência ajuda o Google a indexar com regularidade. Para sites estabelecidos, atualizar conteúdo antigo com informações novas é tão eficaz quanto publicar artigos novos.", 'comum'),
                $safe("O que é E-E-A-T e por que importa para SEO?", "E-E-A-T significa Experiência, Expertise, Autoridade e Confiabilidade — critérios usados pelos avaliadores humanos do Google para medir a qualidade do conteúdo. Páginas com autores identificados, credenciais verificáveis, fontes citadas e conteúdo baseado em experiência real são avaliadas melhor. Importa especialmente para nichos de saúde, finanças e direito.", 'tecnica'),
                $safe("Core Web Vitals ainda influencia o ranqueamento?", "Sim, são fatores de ranqueamento confirmados desde 2021. LCP (Largest Contentful Paint) abaixo de 2,5 segundos, FID abaixo de 100ms e CLS abaixo de 0,1 são as metas. Sites que passam nesses indicadores têm leve vantagem em caso de empate de conteúdo com concorrentes. Use o PageSpeed Insights do Google para medir.", 'tecnica'),
            ];
        }

        // FAQ genérico universal — útil para qualquer nicho não detectado
        return [
            $safe("O que é {$kw_cap} e como funciona na prática?", "{$kw_cap} envolve entender os fundamentos do tema, avaliar as opções disponíveis e escolher a abordagem que melhor se adapta ao objetivo e contexto. A aplicação correta depende do perfil de uso, do orçamento e das necessidades específicas de cada situação.", 'comum'),
            $safe("Quais são as principais vantagens de {$kw_cap}?", "As vantagens incluem ganho de praticidade, economia de tempo ou recursos, e melhora na qualidade do resultado final. O benefício real aparece quando {$kw} é escolhido e configurado de acordo com a necessidade específica — não existe solução única que serve para todos.", 'comum'),
            $safe("Quais erros evitar ao escolher ou usar {$kw_cap}?", "Os erros mais comuns são: escolher sem pesquisar compatibilidade, ignorar o suporte pós-compra e subestimar a curva de aprendizado. Listar os critérios importantes antes de decidir e comparar ao menos três opções com base nesses critérios reduz significativamente o risco de decepção.", 'tecnica'),
            $safe("Quanto custa implementar ou adquirir {$kw_cap}?", "O custo varia conforme o nível de complexidade e a marca. Opções básicas costumam ser acessíveis para quem está começando, enquanto soluções mais completas exigem investimento maior. O critério deve ser custo-benefício — considere durabilidade, suporte e frequência de uso.", 'comum'),
            $safe("{$kw_cap} vale a pena para quem está começando?", "Sim, desde que se comece pelo básico e evolua gradualmente. Não é necessário investir em tudo de uma vez — o aprendizado progressivo reduz erros e ajuda a identificar quais funcionalidades realmente fazem diferença no dia a dia.", 'polemica'),
            $safe("Como saber se {$kw_cap} está funcionando corretamente?", "Verifique os resultados esperados após a implementação. Se o desempenho estiver abaixo do esperado, revise a configuração, consulte a documentação oficial ou busque suporte especializado. Monitorar os primeiros dias de uso é fundamental para identificar ajustes necessários.", 'tecnica'),
            $safe("{$kw_cap} pode ser integrado com outras ferramentas ou soluções?", "A compatibilidade depende do fabricante e do ecossistema escolhido. Antes de comprar ou implementar, verifique se o produto se integra com o que você já usa. Ecossistemas fechados oferecem mais controle mas menos flexibilidade; ecossistemas abertos são mais versáteis.", 'tecnica'),
            $safe("Qual é o suporte disponível para {$kw_cap} no Brasil?", "Prefira opções com garantia nacional, atendimento em português e assistência técnica disponível na sua região. Antes de comprar, pesquise a reputação do suporte no Reclame Aqui e em fóruns especializados.", 'polemica'),
        ];

        if ($is_smarthome) {
            return [
                $safe("Quanto custa montar uma casa inteligente básica?", "Uma configuração inicial com lâmpadas inteligentes, tomadas e um assistente virtual como Alexa ou Google Nest pode custar entre R\$ 500 e R\$ 2.000, dependendo da quantidade de dispositivos. O investimento cresce conforme você adiciona câmeras, fechaduras e termostatos. O ideal é começar pequeno e expandir gradualmente.", 'comum'),
                $safe("Qual é o melhor ecossistema: Amazon Alexa, Google Home ou Apple HomeKit?", "Depende do seu smartphone. Usuários de Android têm melhor integração com o Google Home. Usuários de iPhone preferem o Apple HomeKit pela privacidade e integração nativa. Amazon Alexa é o mais compatível com dispositivos de terceiros e tende a ter preços menores. Nenhum é universalmente melhor — o critério é compatibilidade com o que você já usa.", 'tecnica'),
                $safe("Dispositivos de casa inteligente funcionam sem internet?", "A maioria depende de internet para comandos remotos e assistentes de voz. Porém, alguns dispositivos funcionam localmente via Bluetooth ou Zigbee quando a internet cai — como certas lâmpadas e fechaduras. Verifique antes de comprar se o dispositivo suporta controle local.", 'tecnica'),
                $safe("{$kw_cap} é complicado de instalar para quem não é técnico?", "Dispositivos básicos como lâmpadas e tomadas inteligentes são plug-and-play — basta conectar e configurar pelo app em minutos. Sistemas mais complexos como câmeras externas ou fechaduras podem exigir instalação profissional. Para iniciantes, começar com lâmpadas inteligentes é o caminho mais simples.", 'comum'),
                $safe("A casa inteligente consome mais energia elétrica?", "Não necessariamente — na maioria dos casos, ela reduz o consumo. Termostatos inteligentes evitam desperdício de ar-condicionado, e sistemas de iluminação só acendem quando há presença. Estudos mostram economia de até 30% na conta de luz com automação bem configurada.", 'polemica'),
                $safe("Dispositivos de casa inteligente são seguros contra hackers?", "O risco existe, mas é gerenciável. As maiores vulnerabilidades são senhas padrão não alteradas e firmware desatualizado. Para se proteger: use senhas únicas e fortes em cada dispositivo, ative autenticação em dois fatores quando disponível e mantenha os apps e firmwares atualizados.", 'polemica'),
                $safe("Qual a diferença entre Zigbee, Z-Wave e Wi-Fi em dispositivos inteligentes?", "Wi-Fi é o mais simples de configurar, mas consome mais banda. Zigbee e Z-Wave criam redes mesh entre dispositivos com consumo de energia muito menor — ideais para muitos dispositivos. Zigbee é mais barato e comum; Z-Wave tem menor interferência. Para iniciantes, Wi-Fi é suficiente.", 'tecnica'),
                $safe("Vale a pena investir em casa inteligente em apartamento alugado?", "Sim, com cuidado na escolha. Lâmpadas, tomadas e assistentes virtuais não alteram a estrutura do imóvel e podem ser levados ao mudar. Evite instalar fechaduras inteligentes fixas ou câmeras externas sem autorização do proprietário.", 'comum'),
            ];
        }

        if ($is_mobile) {
            return [
                $safe("Quanto tempo dura a bateria de {$kw_cap} no uso real?", "A duração de bateria varia conforme o uso: quem usa muito redes sociais, câmera e jogos pode consumir uma carga completa em 6-8 horas. Em uso moderado, a maioria dos smartphones modernos dura 1-2 dias. A capacidade em mAh indica o potencial, mas o software de gerenciamento faz tanta diferença quanto o hardware.", 'comum'),
                $safe("{$kw_cap} recebe atualizações de software por quanto tempo?", "Fabricantes como Samsung prometem até 7 anos de atualizações nos top de linha. Xiaomi, Motorola e outros da linha intermediária costumam oferecer 2-3 anos de Android e 4 anos de segurança. Verifique a política oficial do fabricante antes de comprar, especialmente se planeja usar o aparelho por mais de 3 anos.", 'tecnica'),
                $safe("Qual a diferença entre os processadores Snapdragon, MediaTek e Exynos?", "Snapdragon (Qualcomm) é o mais usado em flagships globais — destaque em desempenho e eficiência energética. MediaTek Dimensity cresceu muito e hoje rivaliza em performance com preço menor. Exynos (Samsung) é usado em alguns mercados e tem desempenho variável. Para uso cotidiano, os três funcionam bem nos modelos intermediários e superiores.", 'tecnica'),
                $safe("{$kw_cap} é bom para jogos mobile?", "Depende do processador, memória RAM e taxa de atualização da tela. Para jogos casuais, qualquer aparelho intermediário serve. Para games pesados como Genshin Impact ou Call of Duty Mobile, procure pelo menos 8 GB RAM, processador da série Snapdragon 7 ou Dimensity 8000 ou superior, e tela com 90 Hz ou mais.", 'comum'),
                $safe("Como saber se um smartphone é nacional ou importado?", "Verifique a caixa: smartphones nacionais têm o selo ANATEL e nota fiscal brasileira. Importados não têm garantia legal no Brasil e podem ter bandas de rede incompatíveis com operadoras locais. Comprar importado pode parecer mais barato, mas a assistência técnica e a garantia não cobrem produtos sem certificação nacional.", 'polemica'),
                $safe("Vale a pena comprar smartphone na promoção da Black Friday?", "Sim, mas com critério. Pesquise o preço histórico no Buscapé ou Google Shopping antes da data — algumas promoções inflam o preço original. Priorize aparelhos do modelo do ano anterior em liquidação ou linhas que o fabricante ainda dará suporte por 2+ anos.", 'polemica'),
                $safe("Qual é a câmera mais importante em um smartphone: resolução ou abertura?", "A abertura (f/1.6, f/1.8) é mais importante que megapixels para fotos com pouca luz. Uma câmera de 12 MP com f/1.8 e bom processamento de imagem tira fotos melhores à noite do que uma câmera de 108 MP com f/2.2. Já para fotos com luz, os megapixels importam mais para ampliar imagens.", 'tecnica'),
                $safe("{$kw_cap} tem suporte a 5G?", "Verifique as especificações técnicas do modelo e confirme as bandas 5G suportadas. No Brasil, as principais são n78 e n1. Não basta o aparelho suportar 5G — precisa das bandas corretas para cada operadora. Use o site oficial da Anatel para consultar quais bandas cada operadora utiliza em sua cidade.", 'tecnica'),
            ];
        }

        // FAQ genérico mas útil — baseado no título real, não em template
        return [
            $safe("O que é {$kw_cap} e como funciona na prática?", "{$kw_cap} é um conceito que pode ser aplicado de formas diferentes dependendo do contexto. Na prática, envolve compreender os fundamentos, avaliar as opções disponíveis e escolher a abordagem que melhor se adapta ao seu objetivo. A aplicação correta depende do perfil de uso, do orçamento e das necessidades específicas.", 'comum'),
            $safe("Quais são as principais vantagens de {$kw_cap}?", "As vantagens variam conforme o contexto de uso, mas geralmente incluem ganho de praticidade, economia de tempo ou recursos, e melhora na qualidade do resultado final. O benefício real só aparece quando {$kw} é escolhido e configurado de acordo com a necessidade específica de cada usuário.", 'comum'),
            $safe("Quais erros evitar ao escolher ou usar {$kw_cap}?", "Os erros mais comuns incluem escolher sem pesquisar compatibilidade, ignorar o suporte pós-compra, e subestimar a curva de aprendizado. A melhor forma de evitar decepção é listar os critérios importantes antes de decidir e comparar pelo menos três opções com base nesses critérios.", 'tecnica'),
            $safe("Quanto custa implementar ou adquirir {$kw_cap}?", "O custo varia bastante conforme o nível de complexidade e a marca. Opções básicas costumam ser acessíveis para quem está começando, enquanto soluções mais completas exigem investimento maior. O importante é não escolher apenas pelo preço — avalie custo-benefício considerando durabilidade e suporte.", 'comum'),
            $safe("{$kw_cap} vale a pena para iniciantes?", "Sim, desde que você comece pelo básico e evolua gradualmente. Não é necessário investir em tudo de uma vez — o aprendizado progressivo reduz o risco de erro e ajuda a identificar quais funcionalidades realmente fazem diferença no seu dia a dia.", 'polemica'),
            $safe("Como saber se {$kw_cap} está funcionando corretamente?", "Verifique os resultados esperados após a implementação. Se o desempenho estiver abaixo do esperado, revise a configuração, consulte a documentação oficial ou busque suporte especializado. Monitorar os primeiros dias de uso é fundamental para identificar ajustes necessários.", 'tecnica'),
            $safe("{$kw_cap} pode ser integrado com outras ferramentas ou dispositivos?", "A compatibilidade depende do fabricante e do ecossistema escolhido. Antes de comprar ou implementar, verifique se o produto ou serviço se integra com o que você já usa. Ecossistemas fechados oferecem mais controle mas menos flexibilidade; ecossistemas abertos são mais versáteis.", 'tecnica'),
            $safe("Qual é o suporte disponível para {$kw_cap} no Brasil?", "O suporte varia conforme o fabricante ou provedor. Prefira sempre opções com garantia nacional, atendimento em português e assistência técnica disponível na sua região. Antes de comprar, pesquise a reputação do suporte no Reclame Aqui e em fóruns especializados.", 'polemica'),
        ];
    }

    private function extract_keyword(string $title): string {
        $stop = ['de','do','da','dos','das','e','o','a','para','com','que','em','um','uma','como'];
        $words = explode(' ', mb_strtolower($title));
        $sig = array_filter($words, fn($w) => !in_array($w, $stop) && mb_strlen($w) > 2);
        return implode(' ', array_slice(array_values($sig), 0, 3));
    }
}
