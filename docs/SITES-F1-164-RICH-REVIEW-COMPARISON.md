# F1-164 — Comparação de conteúdo opcional na interface de revisão

Os templates privados passam a oferecer **três composições de Onepage e Landing Page**, acessíveis por uma navegação explícita na interface Laravel de laboratório: Somente FAQ, Somente serviços, FAQ e serviços.

A versão "somente FAQ" utiliza o contrato estrito sites.ai-copy.v2; as versões "somente serviços" e "FAQ e serviços" utilizam o contrato v3, sendo que a primeira usa lista FAQ vazia. Produto, marca, tenant, tema e release não vêm do candidato de texto. Cada composição recebe um releaseRef e um digest SHA-256 próprios, e nenhuma montagem gera estado de aprovação ou publicação.

Para evitar incompatibilidade com a galeria já aprovada, a nova comparação é uma rota distinta /_lab/sites/v1/rich-compare e requer o novo sinalizador SITES_SYNTHETIC_RICH_COMPARE=1, além das proteções anteriores. A interface Blade aceita o seletor richVariant somente nessa rota e acrescenta a seleção às ligações de produto, tema e dispositivo; as rotas legadas e a exportação F1-162 permanecem invariantes.

A suíte F1-164 roda Laravel em PHP 8.3 e 8.4, cobrindo 2 produtos × 3 paletas × 3 variantes × 2 dispositivos, rejeição de parâmetros e IP externos, hash exato do iframe, cabeçalhos de proteção, zero scripts/formulários, isolamento das três composições e compatibilidade do visualizador anterior.

Trata-se de prévia fictícia privada, sem edição ou aceite de cliente, publicação, CRM, ambiente de staging, deploy ou gastos. OP01/OP02/OP04 e dez gates de produção continuam pendentes.
