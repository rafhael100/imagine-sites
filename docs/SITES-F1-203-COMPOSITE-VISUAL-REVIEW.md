# F1-203 — Quatro controles visuais em um visualizador de revisão

A interface Laravel privada agora reúne os quatro ajustes visuais permitidos pela equipe Imagine: tema Classic/Modern/Warm, paletas de marca predefinidas, seis ordens das seções Soluções/Etapas/FAQ e navegação interna on/off da Onepage. As opções são preservadas quando o revisor muda de uma guia para outra; Landing Page continua sem menu extra.

A rota /_lab/sites/v1/visual-change exige processo PHP CLI, APP_ENV=testing, IP de loopback e oito chaves de laboratório habilitadas. Os parâmetros são restritos a enums de produto, dispositivo, tema, ordem, navegação e paleta; nenhum valor de HTML, URL, CSS, identidade de cliente ou lead é aceito. A infraestrutura HTTP não registra a rota fora do laboratório. Os scripts e formulários permanecem ausentes do HTML gerado.

O modo original (Classic, ordem padrão, menu off e paleta default) é mostrado como **sem alteração proposta**, sem fabricar uma revisão ou decisão anterior. Nos demais modos o motor usa SyntheticCompositeVisualRevisionPlan F1-202, confirma os bytes e SHA-256 da versão original, gera um único novo rascunho e destaca a necessidade de outra revisão. A página mostra o tema e a paleta alterados, a comparação de hashes, a prévia anterior dentro de iframe sandbox não tabbable e a prévia nova isolada. Aprovação, publicação, aceite oficial e CRM permanecem desabilitados.

São **216 composições válidas**: 144 Onepages e 72 Landing Pages, incluindo duas configurações originais sem mudança. A suíte de testes PHP/Laravel percorre todas as variantes, verifica SHA-256, estados pendentes, contraste via paletas validadas, ordem DOM, navegação, integridade de ambos HTMLs, ausência de scripts/forms, a retenção dos parâmetros ao alternar guias e falha fechada para parâmetros indevidos/flags ausentes/IP externo.

É uma UI de laboratório e não um editor autenticado ou publicação real. As PRs continuam draft, sem merge na main, integração operacional CRM, VPS, DNS, Cloudflare, dados pessoais ou gastos. OP01/02/04 e os dez gates permanecem bloqueados.
