<?php
namespace GeoMetodoSEO\SEO;

if (!defined('ABSPATH')) { exit; }

class ExternalLinkingService {

    private $max_links = 3;

    /**
     * Sources organized by niche.
     * Format: 'keyword_trigger' => ['url', 'label', 'priority'] (priority: gov/edu=2, other=1)
     */
    private $niches = [

        'tecnologia' => [
            'keywords'  => ['tecnologia','inteligencia artificial','ia','machine learning','deep learning','software','hardware','programacao','codigo','algoritmo','dados','cloud','computacao','rede neural','automacao','robotica','cyberseguranca','blockchain','criptografia','api','desenvolvimento','devops','linux','open source'],
            'sources'   => [
                ['https://www.mit.edu',                   'MIT',                       2],
                ['https://www.ieee.org',                  'IEEE',                      2],
                ['https://www.acm.org',                   'ACM',                       2],
                ['https://arxiv.org',                     'arXiv',                     2],
                ['https://openai.com/blog',               'OpenAI Blog',               1],
                ['https://deepmind.google',               'DeepMind',                  1],
                ['https://blogs.nvidia.com',              'NVIDIA Blog',               1],
                ['https://www.microsoft.com/en-us/research','Microsoft Research',      1],
                ['https://www.wired.com',                 'Wired',                     1],
                ['https://techcrunch.com',                'TechCrunch',                1],
                ['https://www.theverge.com',              'The Verge',                 1],
                ['https://arstechnica.com',               'Ars Technica',              1],
            ],
        ],

        'seo' => [
            'keywords'  => ['seo','marketing digital','google','rankear','posicionamento','busca organica','palavra-chave','keyword','backlink','link building','content marketing','marketing de conteudo','serp','meta description','meta tag','inbound marketing','funil de vendas','lead','conversao','trafego','analytics','copywriting'],
            'sources'   => [
                ['https://moz.com',                           'Moz',                       1],
                ['https://www.semrush.com/blog',              'SEMrush Blog',              1],
                ['https://ahrefs.com/blog',                   'Ahrefs Blog',               1],
                ['https://searchengineland.com',              'Search Engine Land',        1],
                ['https://www.searchenginejournal.com',       'Search Engine Journal',     1],
                ['https://neilpatel.com',                     'Neil Patel',                1],
                ['https://backlinko.com',                     'Backlinko',                 1],
                ['https://blog.hubspot.com',                  'HubSpot Blog',              1],
                ['https://contentmarketinginstitute.com',     'Content Marketing Institute',1],
                ['https://marketingland.com',                 'Marketing Land',            1],
            ],
        ],

        'financas' => [
            'keywords'  => ['financas','investimento','acoes','bolsa','mercado financeiro','renda fixa','tesouro direto','fundo','dividendo','lucro','juros','inflacao','economia','poupanca','aposentadoria','previdencia','cambio','dolar','criptomoeda','bitcoin','forex','trader','analise fundamentalista','balanco patrimonial'],
            'sources'   => [
                ['https://www.bcb.gov.br',            'Banco Central do Brasil',   2],
                ['https://www.cvm.gov.br',            'CVM',                       2],
                ['https://www.b3.com.br',             'B3',                        2],
                ['https://www.bloomberg.com',         'Bloomberg',                 1],
                ['https://www.reuters.com/finance',   'Reuters Finance',           1],
                ['https://www.wsj.com',               'Wall Street Journal',       1],
                ['https://www.ft.com',                'Financial Times',           1],
                ['https://www.investopedia.com',      'Investopedia',              1],
                ['https://www.fool.com',              'Motley Fool',               1],
                ['https://investing.com',             'Investing.com',             1],
            ],
        ],

        'saude' => [
            'keywords'  => ['saude','bem-estar','doenca','tratamento','medicamento','vacina','nutricao','dieta','exercicio','fitness','mental','depressao','ansiedade','cancer','diabetes','hipertensao','colesterol','imunidade','vitamina','suplemento','medico','hospital','clinica','cirurgia','prevencao','longevidade'],
            'sources'   => [
                ['https://www.who.int',                       'OMS / WHO',                 2],
                ['https://www.nih.gov',                       'NIH',                       2],
                ['https://pubmed.ncbi.nlm.nih.gov',           'PubMed',                    2],
                ['https://www.cdc.gov',                       'CDC',                       2],
                ['https://www.anvisa.gov.br',                 'ANVISA',                    2],
                ['https://www.fiocruz.br',                    'Fiocruz',                   2],
                ['https://www.einstein.br',                   'Hospital Einstein',         1],
                ['https://www.mayoclinic.org',                'Mayo Clinic',               1],
                ['https://www.healthline.com',                'Healthline',                1],
                ['https://www.webmd.com',                     'WebMD',                     1],
            ],
        ],

        'ecommerce' => [
            'keywords'  => ['e-commerce','loja virtual','venda online','marketplace','shopify','woocommerce','carrinho','checkout','conversao','taxa de conversao','produto','estoque','logistica','frete','dropshipping','afiliado','programa de afiliados','vendas','cliente','experiencia do usuario','ux','pagamento'],
            'sources'   => [
                ['https://www.shopify.com/blog',          'Shopify Blog',              1],
                ['https://www.bigcommerce.com/blog',      'BigCommerce Blog',          1],
                ['https://www.ecommercebrasil.com.br',    'E-commerce Brasil',         1],
                ['https://www.sebrae.com.br',             'SEBRAE',                    2],
                ['https://abcomm.org.br',                 'ABComm',                    1],
                ['https://www.nuvemshop.com.br/blog',     'Nuvemshop Blog',            1],
                ['https://digitalcommerce360.com',        'Digital Commerce 360',      1],
                ['https://www.e-commerce.org.br',         'E-commerce.org.br',         1],
            ],
        ],

        'educacao' => [
            'keywords'  => ['educacao','aprendizagem','ensino','escola','universidade','curso','treinamento','capacitacao','vestibular','enem','mec','pedagogia','didatica','competencia','habilidade','certificacao','ead','e-learning','metodologia','formacao','graduacao','pos-graduacao','mestrado','doutorado'],
            'sources'   => [
                ['https://www.mec.gov.br',            'MEC',                       2],
                ['https://www.inep.gov.br',           'INEP',                      2],
                ['https://www.capes.gov.br',          'CAPES',                     2],
                ['https://scielo.br',                 'SciELO',                    2],
                ['https://scholar.google.com',        'Google Scholar',            1],
                ['https://www.coursera.org',          'Coursera',                  1],
                ['https://www.edx.org',               'edX',                       1],
                ['https://www.khanacademy.org',       'Khan Academy',              1],
                ['https://www.udemy.com',             'Udemy',                     1],
            ],
        ],

        'ciencia' => [
            'keywords'  => ['ciencia','pesquisa','estudo','artigo cientifico','publicacao','experimento','laboratorio','fisica','quimica','biologia','matematica','astronomia','nasa','espaco','universo','gene','dna','celula','evolucao','clima','meio ambiente','descoberta'],
            'sources'   => [
                ['https://www.nature.com',            'Nature',                    2],
                ['https://www.science.org',           'Science',                   2],
                ['https://arxiv.org',                 'arXiv',                     2],
                ['https://www.nasa.gov',              'NASA',                      2],
                ['https://www.cnpq.br',               'CNPq',                      2],
                ['https://www.sciencedirect.com',     'ScienceDirect',             1],
                ['https://www.researchgate.net',      'ResearchGate',              1],
                ['https://www.springer.com',          'Springer',                  1],
                ['https://www.plos.org',              'PLOS',                      1],
                ['https://www.wiley.com',             'Wiley',                     1],
            ],
        ],

        'negocios' => [
            'keywords'  => ['negocios','empreendedorismo','startup','empresa','gestao','lideranca','estrategia','inovacao','mercado','competitividade','plano de negocios','mba','consultoria','administracao','rh','recursos humanos','marketing','vendas','produto','servico','cliente','lucro','receita','crescimento'],
            'sources'   => [
                ['https://www.hbs.edu',               'Harvard Business School',   2],
                ['https://www.mckinsey.com',          'McKinsey',                  1],
                ['https://www.forbes.com',            'Forbes',                    1],
                ['https://www.entrepreneur.com',      'Entrepreneur',              1],
                ['https://www.inc.com',               'Inc.',                      1],
                ['https://www.sebrae.com.br',         'SEBRAE',                    2],
                ['https://endeavor.org.br',           'Endeavor Brasil',           1],
                ['https://revistapegn.globo.com',     'Pequenas Empresas',         1],
            ],
        ],

        'sustentabilidade' => [
            'keywords'  => ['sustentabilidade','meio ambiente','ecologia','reciclagem','energia renovavel','solar','eolica','carbono','emissao','aquecimento global','biodiversidade','floresta','amazonia','desmatamento','poluicao','agua','oceano','verde','esg','impacto ambiental'],
            'sources'   => [
                ['https://www.un.org/en/climatechange', 'ONU Clima',               2],
                ['https://www.ibama.gov.br',           'IBAMA',                    2],
                ['https://www.inpe.br',                'INPE',                     2],
                ['https://www.greenpeace.org',         'Greenpeace',               1],
                ['https://www.wwf.org',                'WWF',                      1],
                ['https://www.nature.org',             'Nature Conservancy',       1],
                ['https://sustainability.google',      'Google Sustainability',    1],
            ],
        ],

        'direito' => [
            'keywords'  => ['direito','lei','legislacao','juridico','advogado','tribunal','stf','constituicao','codigo','norma','regulamentacao','contrato','processo','peticion','juiz','sentenca','recurso','trabalhista','penal','civil','empresarial','tributario','fiscal','imposto'],
            'sources'   => [
                ['https://www.planalto.gov.br',       'Planalto (Legislacao)',     2],
                ['https://www.stf.jus.br',            'STF',                      2],
                ['https://www.tst.jus.br',            'TST',                      2],
                ['https://www.senado.leg.br',         'Senado Federal',            2],
                ['https://www.camara.leg.br',         'Camara dos Deputados',      2],
                ['https://www.conjur.com.br',         'Conjur',                    1],
                ['https://www.migalhas.com.br',       'Migalhas',                  1],
                ['https://www.jusbrasil.com.br',      'JusBrasil',                 1],
            ],
        ],
    ];

    /**
     * Detect niche based on keyword.
     */
    private function detect_niche(string $keyword): string {
        $kw_lower = mb_strtolower($keyword);

        foreach ($this->niches as $niche => $config) {
            foreach ($config['keywords'] as $trigger) {
                if (mb_strpos($kw_lower, $trigger) !== false) {
                    return $niche;
                }
            }
        }

        return 'seo'; // default fallback
    }

    public function apply(string $content, string $keyword = ''): string {
        $links_added = 0;

        $niche   = $this->detect_niche($keyword ?: $content);
        $sources = $this->niches[$niche]['sources'] ?? [];

        // Sort: priority desc (gov/edu first)
        usort($sources, function($a, $b) {
            return ($b[2] ?? 1) - ($a[2] ?? 1);
        });

        $niche_kws = $this->niches[$niche]['keywords'] ?? [];

        foreach ($sources as $source) {
            if ($links_added >= $this->max_links) {
                break;
            }

            $url   = $source[0];
            $label = $source[1];

            if (strpos($content, $url) !== false) {
                continue;
            }

            // First inserted link is dofollow; rest are nofollow
            $rel  = ($links_added === 0) ? 'noopener noreferrer' : 'nofollow noopener noreferrer';
            $link = '<a href="' . esc_url($url) . '" target="_blank" rel="' . $rel . '">'
                  . esc_html($label) . '</a>';

            $inserted = false;
            foreach ($niche_kws as $trigger) {
                $pattern     = '/\b(' . preg_quote($trigger, '/') . ')\b(?![^<>]*>)(?![^<]*<\/a>)/iu';
                $replacement = '$1 (' . $link . ')';
                $new_content = preg_replace($pattern, $replacement, $content, 1, $count);
                if ($count > 0 && $new_content !== null) {
                    $content  = $new_content;
                    $links_added++;
                    $inserted = true;
                    break;
                }
            }

            // Fallback: append reference note after first paragraph
            if (!$inserted) {
                $ref = '<p style="font-size:13px;color:#666;">Fonte de referencia: ' . $link . '</p>';
                $pos = strpos($content, '</p>');
                if ($pos !== false) {
                    $content = substr($content, 0, $pos + 4) . $ref . substr($content, $pos + 4);
                    $links_added++;
                }
            }
        }

        return $content;
    }
}
