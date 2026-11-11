# F1-151 — Validação com o código atual do Portal F133

Esta entrega executa no CI **as classes PHP reais** do Portal F133, fixadas no commit `a997b7433e70259e05a3539a4e5301c8b5f79915` (Portal PR #324), contra o `SiteProjectCommand` e o schema `CreateProject v1` do Imagine Sites na branch corrente.

O teste `tests/f151-portal-current-create-project-parity.php` consulta diretamente `SiteEngineSchemaDriftGate`, `SiteEngineContractVocabulary` e `SiteEngineCreateProjectCandidateValidator` da versão fixada do Portal. Demonstra que ambos os produtos suportam `onepage` e `landing_page`, oito campos fechados, revisão positiva e referência opaca válida, com recusa de produto desconhecido e campo extra. Inclui regressões em que o Portal corretamente rejeita **chaves duplicadas ou ordem JSON não canônica**, mesmo quando o Sites conseguiria validar o array já decodificado: isso prova que o parser do Sites não deve ser confundido com autenticação ou aceitação direta do wire.

O teste usa SHA fixo para reproduzir exatamente a revisão sob avaliação; futuras mudanças do Portal precisam de nova rodada de paridade e revisão dos resultados.

**Não implementa emissão, HTTP, mTLS/OAuth, assinatura HMAC, identidade de empresa, contrato ativo, consentimento/aceite oficial, publicação nem envio de lead.** A validação é de laboratório e por isso todas as capacidades operacionais permanecem false. OP01/OP02/OP04 e os dez gates de produção continuam BLOCKED; sem merge dev/main, VPS, deploy ou novos custos.
