# F1-138 — Contrato sintético para uma autoridade independente de vínculo

Esta etapa adiciona uma **interface**, não uma integração operacional ao Portal. O contrato `SyntheticLeadBindingAuthoritySource` representa uma futura consulta a uma autoridade autenticada capaz de devolver o vínculo vigente de organização, site, formulário e destino CRM. O código **não** implementa autenticação, assinatura, autorização de sessão, mTLS, vínculo real ou API.

`SyntheticLeadBindingAuthorityPreflight` compara os campos declarados pelo formulário com um snapshot da fonte independente injetada. Ausência ou erro da fonte, revogação, revisão inválida e qualquer divergência negam. Mesmo o diagnóstico `AUTHORITY_MATCH_UNVERIFIED` concede **zero** permissões de aceite, ACK, entrega ou retry. Este snapshot ainda pode ser falsificado se a fonte injetada não for confiável. O laboratório demonstra somente a separação das responsabilidades.

Testes `tests/f138-authority-source-contract.php` cobrem 11 cenários em PHP 8.3 e 8.4, sem banco, rede ou PII. Nenhum contexto de tenant proveniente de GUC é usado como prova de autoridade.

**Antes de produção:** definir emissor confiável e verificável no Portal, identidade de serviço e tenant, vínculo CRM persistente e revisionado, revogação/revalidação, evidência de consentimento e recebimento, controles LGPD e testes independentes AppSec/DBA. A F1-137 já comprovou que a GUC não é IAM. OP04 #36 segue P0, 10/10 gates BLOCKED, sem merge dev/main, deploy, VPS, DNS, Cloudflare ou custo adicional.
