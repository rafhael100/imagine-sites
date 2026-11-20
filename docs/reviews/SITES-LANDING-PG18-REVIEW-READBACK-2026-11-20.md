# Landing Page — leitura independente de observações (PG18, draft)

Continua Sites [#541](https://github.com/rafhael100/imagine-sites/pull/541) e [#542](https://github.com/rafhael100/imagine-sites/pull/542) → #563 HEAD `533bc571d9c04aee0ff5d2cd0fcf8d0ab7b01985` em branch nova. O Portal [#1019](https://github.com/rafhael100/imagine-portal/pull/1019) é fixado em `4a5e1e06376188f85c90bee3d10056ab3ce2302a`.

## Mudança implementada, somente laboratório

- Papel de leitor independente, admitido no PostgreSQL por `session_user` e empresa. O chamador não fornece autorização de empresa ao SQL. RLS FORCE, sem grants diretos em artefatos, pessoas ou diário.
- Função `read_current_review_observation` vincula o evento privado ao artefato custodiado mais recente por empresa/site, pedido/contrato, versão/epoch e hashes do artefato e do ponteiro, exigindo escritor, revisor e site ativos. Não retorna HTML, briefing ou permissões.
- Adaptador PHP abre transação READ ONLY e verifica o briefing Portal candidato antes e depois da leitura. Divergência/revogação observada é negada. Leitura posterior a nova revisão não reutiliza observação antiga.
- Decisão retornada é apenas `observations_recorded` ou `changes_requested`, com estado `review_observation_private_only`. **Nenhuma equivale a revisão formal aprovada, publicação, aceite do cliente ou cobrança.**
- Fixture PG18 de duas empresas com o mesmo UUID de site verifica falta de observação, leitor sem vínculo, acesso cruzado, desativação de revisor/leitor/site, versão supersedida, digest adulterado, privacidade e rollback na revogação observada do Portal.

## Pendências antes de homologar

O cadastro de atores, o produto comprado, o briefing aprovado, a autorização de site e o transporte Portal → Sites são **sintéticos**. O revisor não é identidade humana autenticada pela aplicação Portal; esta consulta não é autorização para release. Faltam fonte oficial de aprovação e versionamento no Portal, RLS/migrations/roles da aplicação, mTLS homologado, fluxo humano de revisão formal, publicação técnica e aceite expresso. Todos os dez gates permanecem **BLOCKED**. Não há deploy, merge, produção, VPS, credenciais, Cloudflare/DNS, pagamento nem Asaas.

Verificação: workflow novo `.github/workflows/sites-landing-pg18-review-readback.yml` em PHP 8.3/8.4 com PG18 descartável. Registrar CI real depois da execução; não considerar testes sintéticos como entrega operacional.

## Evidência do CI (HEAD inicial)

[GitHub Actions #44734530237](https://github.com/rafhael100/imagine-sites/actions/runs/44734530237) finalizou **SUCCESS**, dois jobs PostgreSQL 18 (PHP 8.3 e 8.4). Cada versão registrou `SITES LANDING PG18 PRIVATE CUSTODY PASS: 35 checks`, `PRIVATE REVIEW JOURNAL PASS: 26 checks` e `REVIEW READBACK PASS: 26 checks`. [PR #564](https://github.com/rafhael100/imagine-sites/pull/564) segue draft. Os demais quatro workflows da mesma HEAD tiveram conclusão SUCCESS. A execução de CI não homologou identidades/autorizações reais, Portal em produção, pagamento ou publicação.
