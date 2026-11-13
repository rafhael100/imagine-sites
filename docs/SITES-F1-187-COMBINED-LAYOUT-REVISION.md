# F1-187 — Nova revisão ao mudar simultaneamente ordem de seções e menu

A equipe Imagine pode agora preparar uma mudança de layout que altera **juntos ou separadamente** a ordem de Soluções, Etapas e FAQ e o menu interno da Onepage, criando **uma única nova revisão**. Anteriormente, a interface de revisão permitia comparar os layouts, mas não havia um único serviço de domínio para invalidar a aprovação anterior quando os dois controles fossem modificados.

SyntheticCombinedLayoutRevisionPlan::begin exige o estado sintético AWAITING_PORTAL_ACCEPTANCE, revisão esperada, SHA-256 do HTML original, role imagine_reviewer, um novo releaseRef e dois parâmetros de layout rigidamente tipados (três nomes permutados e bool de menu). Recalcula o HTML original antes de aceitar a mudança. O novo SiteBrief mantém produto, marca, estilo, textos, benefícios, FAQ, serviços e etapas idênticos; só muda ordem e menu. Landing Page não pode ativar navegação.

O próximo artefato passa pela auditoria inerte, QA estruturado e verificação do manifesto; conteúdo sem alteração visível não ganha um falso release. O fluxo original permanece intocado, e o novo volta a DRAFTING sem digest aprovado. Todos os métodos de capacidade de publicação, aceite do cliente, gravação CRM e reaproveitamento de aprovação retornam false.

A suíte de domínio PHP8.3/8.4 verifica **48 mudanças válidas** partindo de layouts completos (2 produtos × 3 estilos × ordens e menu), rejeita permutações inválidas, revisão stale, origem adulterada, role não autorizada, nome de release repetido, Landing com menu e mudanças invisíveis quando não existem seções. Está integrada ao workflow principal do Sites Engine, sem mais um workflow pesado por PR.

Este plano continua **somente em memória e com dados sintéticos**. O rótulo imagine_reviewer não é identidade autenticada. Autoridade de Portal, consentimento, CRM real, homologação, retenção e dez gates de produção permanecem bloqueados; sem merge, deploy, VPS ou gasto adicional.
