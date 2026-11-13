# F1-212 — Revisão única de tema, cores, ordem, menu e cartão social

O motor já oferecia estas configurações em telas separadas: tema visual, paleta de marca, ordem das seções, menu interno de Onepage e título/descrição Open Graph/Twitter. A F1-212 reúne as escolhas na rota privada `/_lab/sites/v1/composite-social`.

O revisor aceita apenas sete parâmetros enum (produto, dispositivo, tema, ordem, menu, paleta e metadados sociais on/off), sob PHP CLI, APP_ENV=testing, IP loopback e quatro flags explícitas incluindo `SITES_F212_COMPOSITE_SOCIAL_REVIEW=1`. A Landing Page não pode ativar menu extra. As opções de layout e as cores são obtidas exclusivamente do `SyntheticVisualPresetCatalog`; não há entrada de CSS, URL externa, upload, HTML, lead ou dados reais.

Cada tela cria um `SyntheticCompositeVisualPrivateFixture` com a mesma versão que pode ser exportada na F1-209. Antes da apresentação, `SyntheticPrivateSocialCardReadbackV1` confere os quatro arquivos canônicos da versão, o SHA-256 e o cartão textual, quando ativado. Não há acesso a rede social, imagem externa nem publicação. O revisor usa a interface Laravel já existente, apresenta hash da versão original e selecionada, lista as configurações com links locais e mantém os critérios de revisão pendente, noindex, CSP e iframe sandbox.

Os links de produto, tema e dispositivo preservam as escolhas combinadas; ao trocar Onepage com menu ligado por Landing Page, o menu muda para off para evitar uma configuração inválida. Todas as escolhas permanecem determinísticas e só pertencem ao laboratório.

A matriz PHP 8.3/8.4 testa 48 visualizações principais, paletas adicionais, menu on/off, persistência de seletores, SHA exato do iframe, ausência de scripts e formulários e negação de parâmetros ou IP externo. Os revisores antigos continuam ativos e independentes.

Este é um produto de demonstração privada, não o painel de cliente. OP01/OP02/OP04 e dez gates de produção seguem bloqueados; sem autenticação real, CRM, domínio público, deploy ou novos custos.
