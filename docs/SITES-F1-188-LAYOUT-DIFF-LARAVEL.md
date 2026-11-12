# F1-188 — Comparar antes/depois de uma revisão de layout na interface Laravel

A F1-187 introduziu o serviço de domínio que reabre uma revisão ao mudar a disposição das seções e/ou a navegação. A F1-188 conecta esta regra ao visualizador interno Laravel, tornando visíveis o SHA anterior, o SHA novo, as opções alteradas e as revisões sintéticas.

A nova rota /_lab/sites/v1/layout-diff recebe apenas enums de produto, tema, dispositivo, ordem e menu on/off, e exige APP_ENV=testing, PHP CLI, loopback e todas as flags de laboratório F1-181 + SITES_F188_LAYOUT_DIFF. Um pedido que não representa mudança visual real (ordem padrão e menu desativado) retorna 404; Landing Page não aceita menu on.

O código constrói uma versão original fixa com cópia sintética v4, simula uma aprovação interna, chama SyntheticCombinedLayoutRevisionPlan para solicitar mudança, e exige estado DRAFTING com digest aprovado revogado na revisão 4. Em seguida renderiza uma **prévia independente**, compara todos os bytes e digest com o plano e exibe uma nova página de revisão interna e seu dossiê. O texto da interface esclarece que a prévia é uma simulação independente, não a continuidade persistida do workflow: nenhuma identidade de cliente ou serviço é autenticada.

O revisor vê os hashes original e novo, revisão anterior 3, revisão reaberta 4, se a ordem mudou e se o menu mudou. A interface mantém a seleção de ambos ao trocar produto, tema, dispositivo e configuração. Não há formulário, JavaScript, request CRM, upload ou endpoint real.

A suíte F1-03 PHP 8.3/8.4 cobre 48 composições distintas em desktop/mobile (96 respostas), DOM na ordem solicitada, SHA exato do iframe sandbox, avisos de revisão pendente, headers de segurança, estados sem mudança/forjados e isolamento de rotas anteriores. A execução completa no Docker F1-83 deve permanecer verde.

Sem cliente, faturamento, domínio público, integração CRM real, deploy nem merge em dev/main. OP01/02/04 e 10 gates de produção bloqueados.
