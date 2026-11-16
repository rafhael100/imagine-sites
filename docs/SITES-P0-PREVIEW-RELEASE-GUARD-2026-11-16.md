# Sites P0 — avanço independente, sem promoção operacional

16/11/2026. DRAFT / NO-MERGE / NO-CHARGE / NO-PUBLISH. **A meta Portal → pedido conciliado → projeto operacional → prévia ainda não foi atingida.** Não se deve usar as demonstrações desta entrega para afirmá-la.

## Bases e preservação

Sites483 `814f0afd0741821696b42ff0c7fa180af6286ba1` e Portal901 `5d88826d0f8a83455f11b44dbfd66d5b462ce267` inspecionadas antes das alterações. Trabalho novo em Sites484 e Portal916, ambas DRAFT. As branches originais/main/dev e os gates de admissão/publicação foram preservados.

Console Leaseweb acessível nesta rodada. Verificações somente leitura em16/11 aproximadamente17:31 Brasília: `docker ps` mostrou Sites app e PostgreSQL saudáveis, proxy em execução com127.0.0.1:18090; CRM18081 e demais serviços continuaram presentes. Sem comandos de instalação/recriação/restart/reload/migração ou mudanças de rede/volume/certificado. TLS verify-full já comprovado na rodada483 foi reaproveitado, não reensaiado.

No contêiner de compra Portal consultado, `ls /run/secrets` listou apenas `preview-runtime.json`; nenhum valor foi aberto. Isso **não prova ausência em todos os cofres da empresa**, mas não disponibiliza o token fixo requerido pelo bootstrap901. Uma busca nominal por filename no console sofreu tradução underscore/hífen pelo teclado noVNC e não foi contabilizada como evidência de ausência. Nenhum token Asaas foi lido, solicitado pelo chat ou utilizado. Nenhuma consulta ou conciliação oficial foi realizada.

## Sites484 — workspace demonstrativo persistente

Novo namespace `sites_preview`, migration aditiva000004 e PrivatePreviewWorkspace. Reutiliza SiteBrief/StaticSiteRenderer existentes, sem executar IA ou reescrever o renderer.

- Briefing estruturado, produto Onepage/Landing Page, HTML escapado e SHA256 dos bytes finais persistidos.
- Propósito **demonstration_only** imutável, banner obrigatório de demonstração sem contratação/pagamento/aceite. Não existe promoção para pedido operacional.
- FORCE RLS por login SQL e empresa, privilégios mínimos e principal vigente/revogável; rejeita owner/superuser/SET ROLE e snapshots de isolamento obsoletos.
- Revisões append-only, CAS/lock por projeto e limites de espera. Revisão editorial por principal SQL diferente do autor, vinculada ao hash/revisão exatos; revalidação antes do commit.
- Auditoria de projetos/versões/revisões append-only. Isso não substitui o controle/auditoria operacional de emissão de identidades ou concessões.
- Não emite aceite de cliente, assinatura comercial, pagamento, entitlement ou publicação. Separação de dois logins SQL de CI não é ação humana autenticada no Portal.

Demonstração de navegador usa snapshots HTML recuperados do banco sob principal autorizado, servidos somente no loopback do runner com BasicAuth efêmero de CI. **Esse viewer não representa o endpoint operacional Portal↔Sites, nem sua identidade/mTLS.** Artifacts contêm dois HTMLs e screenshots desktop/mobile, sem dados de clientes ou credenciais. Nenhuma nova rota/browser page foi instalada na VPS.

## Portal916 — guard da liberação financeira

FullInstallationBindingSource passa a envolver o binding nativo no SandboxSettlementFactory explícito. Exige termos imutáveis de contrato/revisão/produto/oferta, total/3parcelas, digest e política de liberação correspondentes; ausência ou política não aprovada nega antes da leitura PSP. O valor da primeira parcela não pode ser promovido a quitação do total.

Ledger candidato `installation_release_terms` tem RLS, validade, revogação herdada, imutabilidade e auditoria. O produtor autenticado de pedidos/contratos/ofertas deve escrevê-lo; esse produtor **não foi habilitado por esta entrega**. Não há seeds operacionais, flag local de paid ou configuração de produção. O catálogo e seus vencimentos foram preservados. A política full_installation_settled usada nos testes é fixture explícita, não decisão comercial aprovada; o código não agrega três pagamentos nem autoriza automaticamente serviços após D0 ou após um receipt isolado.

A fonte financeira de baixo nível continua produzindo apenas evidência isolada de pagamento; a emissão comercial necessita fontes independentes de contrato, liberação e revogação. As implementações negadoras existentes não foram substituídas por fixtures na aplicação.

## Testes e correções

Código Sites testado: `7c2eb2ba005557023ba8cbee6869bbb156709cb8`. Código Portal testado: `0e9b6f40361008fdacdbf7280dd1eb6d91bfdddb`.

- Sites:[CI43955097973](https://github.com/rafhael100/imagine-sites/actions/runs/43955097973) aprovado,28 asserts de persistência/versão/hash/revisão/RLS/revogação/isolamento; confirmação específica de rollback populado e inspeção RLS restrita negados;13 verificações de navegador autenticado/propósito/desktop1440/mobile390. Logs finais confirmados, incluindo marcador específico do verificador. Treze check runs success na SHA, incluindo matrizes e duplicados push/PR; não treze suítes exclusivas.
- Portal: [CI43952268322](https://github.com/rafhael100/imagine-portal/actions/runs/43952268322),9 asserts aprovados sobre termos nativos e primeira parcela; PSP somente fixture. Regressões PG18 binding PHP8.3/8.4,43 do verificador,15 paridade, Laravel, OAuth offline e preview privado existente também passaram. Onze check runs success e dois Node duplicados cancelados com substitutos aprovados; não são treze suítes únicas aprovadas.
- Guard F21 permaneceu inalterado. Falso positivo com módulo Python secrets no YAML foi corrigido usando entropia local sem contexto de credenciais do GitHub.
- Rollback de namespace com dados continua proibido. Esquema vazio pode ser removido transacionalmente por migrador já privilegiado capaz de provar ausência completa de registros, sem CASCADE; dependências desconhecidas abortam.
- Revisão dos logs identificou um **falso positivo de CI**: Laravel standalone retornava0 após falhar na checagem de destino. Verificador agora encerra com1 em exceções, exige marcador OK e testa configuração de cluster inválida. Preflight compara banco/login/porta/IP backend normalizado e system_identifier com o contêiner PostgreSQL observado do job, antes de criar schema. Falha não prossegue para migração. A SHA63ae40a não comprova o teste de rollback; foi superada pela correção posterior.
- Testes são em instâncias GitHub descartáveis, não migrações operacionais. Uma conexão nova prova persistência, não restauração externa. Screenshots e testes automáticos não constituem revisão humana independente.

## Recuperação e limites

Backup/restore reais da rodada482 e TLS da483 continuam evidências históricas limitadas. Não houve nova cópia externa, destino homologado, custódia ou restore externo. A futura recuperação deve incluir Sites core + workspace/versões/briefings/revisões/auditoria e seus grants/RLS, além do ledger Portal separado e chaves em custódia própria. Restaurar em banco novo isolado, reconciliar revogações atuais antes de servir, preservar SHA e idempotência; não reativar permissões expiradas. Validação de identidade de writer em triggers requer plano de restauração controlado/DBA, nunca desativação de proteção em serviço aberto. Nada disso foi declarado homologado nesta rodada.

## Estado operacional e próximos requisitos

| Etapa | Estado real |
|---|---|
| Sites existente/18090/PostgreSQL TLS | infraestrutura preservada; verificações readonly atuais e TLS histórico483 |
| mTLS operacional Portal↔Sites | não homologado; listener existente permanece default-deny e autoridade de serviço não conectada |
| Cliente/empresa/pedido/contrato oficial Portal | produtor e vínculos operacionais não conectados; termos candidatos não são contratação |
| Asaas Sandbox oficial | sem token/conta autorizados acessíveis no runtime consultado; nenhuma chamada |
| Projeto/prévia de pedido liberado | não executado; só workspace demonstrativo de CI |
| Revisão editorial | persistência e separação SQL testadas, sem fingir decisão humana |
| Aceite do cliente | fonte negadora preservada; não houve aceite humano autenticado da versão |
| Revogação independente/backup externo | pendentes de provedor/custódia/destino e homologação |
| Publicação/rollback operacional | NO-GO; nenhuma publicação, DNS ou alteração de clientes |

Requisitos para a próxima execução real: autoridade Portal com usuário/empresa de homologação e concessão Sites específica, identidade técnica/certificado e revogação sob custódia aprovados; fonte de pedido/contrato/oferta e política comercial assinada/versionada; conta/token Sandbox montados pelo mecanismo seguro; destino externo de backup e revisão independente. Não cadastrar principal fictício como operacional para destravar.

URL existente continua configurada em `https://localhost:18090` apenas na VPS/túnel autorizado, painel técnico protegido, não uma nova prévia de cliente acessível pelo computador do usuário. Previews desta entrega estão nos artifacts de CI e não receberam URL de homologação na Leaseweb. Os dados necessários de identidade/custódia devem ser disponibilizados por mecanismo seguro, nunca por envio de senhas/chaves neste chat.
