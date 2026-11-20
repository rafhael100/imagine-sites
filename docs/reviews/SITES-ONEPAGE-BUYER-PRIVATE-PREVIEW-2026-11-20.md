# Onepage — prévia privada do comprador (candidata, NÃO operacional)

20/11/2026. Branch isolada a partir de Sites #569, base SHA
`8172e5cc14e97951e529c843059c214e05c33db5`.
Sites #541/#542 são referências históricas; trilha Landing Page #569 é
distinta. Não transferimos evidência de aprovação entre produtos.

## Entrega

`OnepageBuyerPrivatePreviewCandidate` reutiliza os parsers,
o renderer, o plano e o ponteiro de revisão privados existentes no Sites.
Não duplica pedido/response S2S do Portal #1003 + Sites #552. O retorno é
HTML determinístico para prévia privada, digest SHA-256 e revisão exata,
mas marca `clientFinalAccepted=false`,
`publicationAuthorized=false` e `canPublish=false`
em todos os casos; não há rota HTTP, provider, fila, banco ou deploy.

O contrato requer quatro fontes independentes, todas NÃO conectadas
por padrão (construtor sem injeção recusa tudo):

1. **Fonte oficial de bytes imutáveis**, comprador responsável e
   versão explícita do briefing no Portal — contrato fail-closed #976.
2. **HEAD corrente autenticado** do Portal para empresa, contrato,
   pedido, site, versão e os digests; o transporte/dispatcher
   #1003/#552 ainda é apenas candidato sem peer mTLS operacional.
3. **Confirmação do comprador titular** da sessão, sobre a versão
   e os digests exatos, com declaração expressa dos direitos de uso,
   referência persistente e titularidade independente do tenant.
4. **Aprovação atual ou revogação do revisor autorizado**, distinta
   do comprador, sobre os mesmos bytes e revisão, com auditoria e ACL.

Outra pessoa compradora **da mesma empresa** não ganha acesso por
pertencer ao mesmo tenant. Revisão alterada requer uma NOVA confirmação
do comprador e uma NOVA aprovação do revisor. O domínio **não é
exigido para prévia**; será exigido no gate de publicação, em separado.

O contrato recusa campos extras de autoridade. Busca o registro exato,
testa HEAD, comprador e revisor, gera a prévia usando o renderer existente,
e repete leitura da origem, HEAD e testemunhas para detectar qualquer
alteração observada. **Duas leituras não provam atomicidade**:
antes de conectar HTTP real, o Portal precisa de CAS/epoch
transacional, identidade e revogação persistentes, segurança e AppSec.
A futura rota deve validar a identidade da sessão no servidor,
usar CSP sandbox, noindex, ausência de cache compartilhado,
CSRF para mutações, e não disponibilizar artefatos publicamente.

## Evidência e limitações

`php tests/onepage-buyer-private-preview-offline.php`
usa somente fixtures sintéticas; cobre comprador certo e outro da mesma
empresa, tenant/pedido/site, revisão obsoleta, alteração de hash,
direitos não declarados, revisor revogado ou coincidente, troca
durante dupla leitura, produto Landing Page recusado, replay
idempotente, e prévia sem domínio. Workflow novo roda PHP 8.3/8.4
em PR draft pinada à HEAD/base correta. Aguardar CI da SHA final
antes de alegar aprovação.

Revisão da fonte Portal #976 constatou que o D0
`briefing_available` NÃO contém os bytes ricos do briefing
imutável nem confirmação do comprador e aprovação independente.
O laboratório `apps/portal/labs/onepage-confirmation-private`
não representa origem homologada de aprovação do briefing.
Não foi criado adaptador oficial nem habilitado runtime.

**Próxima ação**: identificar e homologar no Portal a origem
versionada e o controle humano do comprador/revisor com os bytes,
auditoria e revogação durável; implementar CAS e identidade S2S;
depois avaliar ligar a prévia privada. Prévia privada, aceite
final expresso e publicação técnica permanecem três gates
distintos. Gate Sites #38 e os dez gates operacionais: NO-GO.
Sem main, merge, VPS, Cloudflare/DNS, credenciais, produção,
Asaas, PSP, compra, instalação ou publicação.
