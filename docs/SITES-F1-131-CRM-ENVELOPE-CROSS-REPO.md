# F1-131 — Paridade do envelope técnico de leads Sites↔CRM (sem conteúdo)

## Motivação e origem fixada

O CRM Imagine já possui um validador independente de **proposta sintética** de metadados de lead, `SyntheticLeadEnvelopeV1Inspector` (CRM F3-11), na branch de desenvolvimento do [CRM PR #49](https://github.com/rafhael100/imagine-crm/pull/49). O motor Imagine Sites ainda não possuía código testado contra essa estrutura: alterações entre projetos poderiam passar despercebidas.

A F1-131 implementa uma estrutura `SyntheticCrmLeadEnvelopeV1Draft` no Sites, que aceita **somente seis referências UUID sintéticas** e produz oito campos na ordem canônica: `protocol` (`imagine.lead-intake`), `schema_version` (inteiro 1), `organization_reference`, `binding_reference`, `submission_reference`, `site_reference`, `form_reference` e `destination_reference`.

O construtor recusa UUID ausente, malformado ou nulo e normaliza para minúsculas. O método `canAuthorizeDelivery()` é **sempre falso**. UUIDs são alegações técnicas não autenticadas; não provam propriedade, vínculo Sites/CRM, consentimento, persistência, identidade da origem ou permissão.

## Prova independente automatizada

O workflow `.github/workflows/sites-f1-131-crm-lead-parity.yml` obtém o código-fonte público do CRM **no commit fixo `a2f29c8e19185e3451fe953f6fe9c9c9bcbbe3ef`**, valida que o checkout coincide com esse SHA e executa `tests/f131-crm-lead-envelope-cross-repo.php` em **PHP 8.3 e 8.4**, sem Composer, SQL, HTTP, serviços externos ou despesas.

O teste executa a implementação real do validador CRM sobre o envelope criado pelo Sites e exige:
- `STRUCTURE_ONLY_UNVERIFIED` para metadados de formato válido, **nunca** permissão de aceitar submissão, confirmar recibo durável ou entregar ao CRM;
- equivalência do formato JSON técnico após serialização/deserialização;
- rejeição de payload/PII/consentimento ou T0 informados pelo solicitante, campos extras ou ausentes, UUIDs inválidos/nulos, protocolo/versão divergentes;
- identidade estável da mesma submissão e separação de submissões distintas;
- reordenação de campos não se transforma em autorização;
- normalização determinística das referências UUID pelo Sites.

## Limites e dependências

A proposta **não está integrada** a um endpoint CRM real, banco de dados, formulário, fila ou navegador; não envia nem persiste leads, tampouco fornece idempotência global, timestamp servidor T0, destino CRM autenticado ou consentimento. É uma prova de compatibilidade de **estrutura**, não uma API final. A branch do CRM está em PR draft e pode mudar; o SHA é intencionalmente fixo para detectar incompatibilidades explicitamente.

Para OP04 continuar, é necessário firmar contrato de integração versionado e revisado SEG/DBA com identidades autenticadas, autorização de vínculo, dois pontos de entrada fisicamente independentes, fonte durável T0, dedup concorrente, confirmação confiável, proteção e retenção LGPD, testes de recuperação e validação AppSec independente. A F1-130 protege a **pré-condição sintética de reenvio** contra A→B→A, mas não transforma o envelope F1-131 em autorização.

Sem merge dev/main, deploy, cliente real, PII, VPS/Cloudflare, domínio ou novos gastos. **10/10 gates de produção BLOCKED.**
