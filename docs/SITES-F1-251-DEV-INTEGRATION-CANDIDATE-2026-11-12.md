# Imagine Sites — candidato de integração F1-251 (12/11/2026)

**Natureza:** PR draft de consolidação contra `dev`, sem merge, deploy ou qualquer publicação.

## Origem e conferência da base

- Fonte do candidato: [PR #291](https://github.com/rafhael100/imagine-sites/pull/291) em SHA `71247c26e1d815f439e8e7ab3b83e3ddd7faafcb`.
- Base `dev`: `71b8ffe51139d0e70ed32591b07f295fb7bf5fe3`; comparação inicial de **998 commits à frente e zero atrás** (antes dos commits da documentação).
- O candidato anterior [PR #286](https://github.com/rafhael100/imagine-sites/pull/286) passou **88/88 workflows** no seu próprio head. Isso não substitui os testes do novo head.
- Diferenças da API `compare` são truncadas em 300 caminhos; os workflows **F1-240/F1-241**, com `git diff` completo, devem gerar inventário novo deste exato SHA. Caminhos sensíveis devem receber revisão AppSec/DBA/Portal/CRM.

## Entregas adicionais implementadas e testadas

| Entrega | Descrição implementada | CI do head próprio |
| --- | --- | --- |
| [#287 — F1-247](https://github.com/rafhael100/imagine-sites/pull/287) | `SiteBrief` rejeita controle invisível/bidi e URLs em texto de entrada; mantém HTML escapado | 14/14 |
| [#288 — F1-248](https://github.com/rafhael100/imagine-sites/pull/288) | Proveniência exata copy/renderer → draft privado → bundle SHA → revisão, com testes adversariais | 7/7 |
| [#289 — F1-249](https://github.com/rafhael100/imagine-sites/pull/289) | Navegação e CTA com área mínima 44 px; Chrome mede os alvos em 1365/390/320 | 18/18 |
| [#290 — F1-250](https://github.com/rafhael100/imagine-sites/pull/290) | Composer `--no-scripts --no-plugins`; Artisan/package discover/route cache com UID 10001, builds Docker reais | 8/8 |
| [#291 — F1-251](https://github.com/rafhael100/imagine-sites/pull/291) | Chromium mede reflow a 200% de fonte raiz em 18 páginas completas, sem overflow horizontal | 5/5 após repetir falha inicial de inicialização Chromium |

Os números acima são de **heads individuais**, não da validação desta nova branch. Todas as imagens e HTMLs são fictícios, `noindex`, sem formulários, entrega a clientes, recursos remotos ou autorização de publicação.

## Registro de governança e segurança de branch

Em um comando de criação do teste F1-249, houve um **commit acidental na branch padrão `main`** (`e76a758a27a25c717f5d99a232ace01d9ade6e46`). A ferramenta de atualização de referência, com `force-with-lease` vinculado exatamente a esse SHA, **restaurou imediatamente `main` ao commit original `e95c481176f48c28ade1db0a5bcd2777fe7dcb3e`**. Foi confirmado pela API que `main` voltou ao SHA anterior, e o teste foi recriado na branch isolada correta. Não houve deploy, merge em `dev` ou execução da aplicação em produção.

Esse episódio reforça o P0 da [issue #281](https://github.com/rafhael100/imagine-sites/issues/281): a API reportou `protected:false` tanto em `dev` quanto em `main`, e a configuração de branch protection deve ser executada e validada por responsável administrativo autorizado antes de qualquer integração.

## Bloqueios

1. Revisão independente dos arquivos sensíveis por AppSec, DBA, Portal e CRM, com registro de findings e aprovação específica antes de merge em `dev`.
2. Proteções `dev/main` e status checks obrigatórios, issue #281.
3. Para qualquer serviço real: OP01 autenticação e revogação; OP02 banco isolado, backups/restore; OP03 licença e publicação Cloudflare/observação independente; OP04 captação/CRM em dois meios; OP05 IA/Agents/n8n; gate #38 para VPS/DNS/custos/segredos.
4. Os **dez gates** em `config/operational-readiness.json` permanecem `status=BLOCKED`, `non_operational=true` e falsos. `AGI-11-RB01` continua desativado.

**O resultado autorizado desta etapa é somente um candidato verificável para revisão.** Testes verdes não concedem aceite do cliente, merge, aprovação de publicação, indexação, lead real, envio ao CRM nem operação Leaseweb/Cloudflare.
