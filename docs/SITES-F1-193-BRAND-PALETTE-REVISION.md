F1-193 — Nova revisão obrigatória quando as cores de marca mudam

O catálogo de paletas F1-190 e o comparador visual F1-191 ainda não eram suficientes para alterar uma página que já tivesse recebido revisão interna. SyntheticBrandPaletteRevisionPlan::begin implementa um serviço de domínio específico para essa mudança, sempre produzindo nova versão de página e invalidando a aprovação anterior no fluxo sintético.

O método exige estado AWAITING_PORTAL_ACCEPTANCE, role de laboratório imagine_reviewer, revisão exata e digest da versão original conferido byte a byte pelo próprio renderizador. Os identificadores de release anterior e novo devem ser diferentes. Recebe uma paleta tipada SyntheticBrandPaletteV1 ou null para voltar às cores do preset. Se a paleta nova for equivalente por valor à anterior, recusa criar uma versão fictícia sem mudança visual. O sistema não aceita CSS arbitrário, fontes, arquivos ou URLs nesse fluxo.

A próxima SiteBrief preserva produto, marca, copy, serviços, FAQ, etapas, tema, ordem de seções e menu exatamente como antes, alterando somente a paleta. O novo HTML recebe SHA-256 próprio, auditoria inerte e QA; o workflow volta a DRAFTING na revisão seguinte com artifactDigest=null. A aprovação sintética original permanece imutável e não acompanha a nova versão.

A matriz principal PHP 8.3/8.4 testa 36 trocas válidas: 2 produtos × 3 temas × seis operações (Original→Oceano/Ameixa/Floresta; Oceano→Original/Ameixa/Floresta). Adiciona negações de no-op com objetos separados equivalentes, release repetido, papel de agente, revisão obsoleta, origem ainda não aprovada e HTML adulterado. Nenhum resultado autoriza publish/aceite do cliente/CRM real.

Segurança: a identidade imagine_reviewer ainda é uma etiqueta sintética, não IAM do Portal. Aprovação de cliente, integração real, produção, LGPD, auditoria independente e 10 gates continuam bloqueados. Sem deploy, main, VPS ou gastos.
