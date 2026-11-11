# F1-178 — Navegação interna opcional na Onepage

O motor passa a suportar links de navegação para Soluções, Como funciona e FAQ quando estas seções realmente existem. A equipe Imagine pode habilitar a funcionalidade explicitamente para uma **Onepage**. Landing Pages não recebem esse menu para manter seu layout de conversão enxuto.

Os links utilizam apenas fragmentos fixos #solucoes, #etapas e #perguntas. A ordem acompanha a disposição escolhida na F1-175, sem aceitar URLs externas, eventos, scripts, âncoras arbitrárias ou texto de IA para identidade das seções. CSS de wrap responsivo é injetado apenas se houver uma seção opcional e se a navegação for explicitamente ativada.

SiteBrief inclui sectionNavigation=false por padrão. Versões anteriores mantêm seus HTMLs byte a byte. Um briefing sem seções extras não produz marcação ou CSS novo, mesmo com opt-in. O InertPreviewAudit foi atualizado para reconhecer **somente** os três anchors adicionais, exigindo que os destinos existam no HTML.

SyntheticStructuredDraftAssembly recebe a flag de navegação separada do conteúdo estruturado; nenhum schema sites.ai-copy.v1..v4 recebe esse controle. Ajuste de tema, ordenação e edição de texto mantêm a decisão anterior, e revisões comuns não podem ligar/desligar a navegação incidentalmente. Testes PHP 8.3/8.4 cobrem 3 paletas × 6 disposições, compatibilidade, integridade, QA, rejeição de Landing e de comando forjado.

É uma prévia privada sintética e não um menu público homologado. OP01/02/04 e 10/10 gates de produção permanecem bloqueados, com PR draft encadeado, sem merge, cliente, CRM, deploy ou custo.
