# Imagine Sites P0 — TLS aplicado no ambiente existente

16/11/2026. DRAFT / NO-MERGE / NO-CHARGE / NO-PUBLISH. Esta entrega NÃO conclui o fluxo Portal→pagamento→prévia.

## Bases e execução real

Sites482:33624701882b4c8b2e42e5e99b8b44f692c71028. Portal759:975bb83a1937906b5b0bf33036410f99a553394f. Ambas DRAFT/não merged, HEADs consultadas. PR483 funcional **dd91486352bdc45b68e1b6289546759cc36360f5** foi verificada no CI antes da operação na VPS. Não foram reaproveitadas credenciais de outros projetos.

A sessão Leaseweb foi reaberta pelo painel já autenticado e permitiu retomar o console root existente, sem senha/chave no chat nem novo acesso SSH. Inventário fresco na VPS às12:05 UTC (09:05 Brasília):53 contêineres,19 redes,45 volumes;8vCPU, aproximadamente22694MiB disponíveis,272GiB livres e carga baixa. O inventário manteve o bloqueio por namespace já existente: **não foi usado o procedimento de criação/start nem escolhida outra porta**.

O projeto continua **imagine-sites-p0-staging**, ingresso **127.0.0.1:18090**, banco/volume/redes existentes. Antes da mudança foi salvo snapshot privado dos53 contêineres. Certificados próprios do PostgreSQL foram gerados na pasta privada Sites; chave da CA não foi montada em serviço. CA30dias/leaf14dias: renovação controlada é pendência de manutenção, não certificação de longo prazo.

Às12:07 UTC (09:07 Brasília), o PostgreSQL18 recebeu certificado e configuração por **reload/SIGHUP**, sem restart/recriação do banco. HBA anterior preservado; TCP sem criptografia passou a ser rejeitado, mantendo autenticação local do operador. As verificações comprovaram conexão verify-full, CA correta e hostname correto, rejeição de CA incompatível, hostname incorreto e plaintext. MínimoTLS1.2.

Às12:08 UTC (09:08 Brasília), somente a configuração do contêiner **app Sites** foi atualizada, usando **a mesma imagem existente**, sem build/pull/reinstalação. CA pública própria montada no app, DB_SSLMODE=verify-full e DB_SSLROOTCERT definidos. Não houve recriação do ambiente, banco, volume, redes ou proxy. A comparação confirmou **52 outros contêineres com IDs, estado, StartedAt e RestartCount inalterados**, incluindo banco/proxy Sites e CRM/demais projetos. O proxy recebeu apenas reload para resolver o app atualizado.

O verificador executado dentro do Laravel confirmou verify-full com hostname db, runtime sem privilégios extras, CA/hostname negativos e FORCE RLS/default-deny preservados. HTTPS privado após a mudança: **/up200 autenticado; /401 sem autenticação**, com CA/hostname do ingresso verificados. Não houve liberação de API comercial/publicação.

## Evidências verificáveis e limites

Arquivos privados persistentes no host, sem credenciais nos resultados:

- /srv/imagine/sites-p0-private/evidence/pg-tls-existing-before.json
- /srv/imagine/sites-p0-private/evidence/pg-tls-server-result.json
- /srv/imagine/sites-p0-private/evidence/pg-tls-app-result.json
- /srv/imagine/sites-p0-private/evidence/laravel-readiness-after-tls.json

Transcrição finita observada do resultado servidor:

```json
{"at":"2026-11-16T12:07:11.106255+00:00","can_accept_orders":false,"can_publish":false,"database_container_preserved":true,"hostname_mismatch_denied":true,"minimum_tls":"TLSv1.2","plaintext_tcp_denied":true,"schema":"sites.pg18.inplace-tls.v1","tls_reload_only":true,"untrusted_ca_denied":true,"verify_full_trusted":true}
```

Resultado app:

```json
{"app_configuration_refreshed":true,"at":"2026-11-16T12:08:02.994621+00:00","ca_and_hostname_negatives":true,"can_accept_orders":false,"can_publish":false,"database_and_proxy_preserved":true,"other_existing_containers_unchanged":52,"port":18090,"runtime_verify_full":true,"same_app_image":true,"schema":"sites.existing-app.verify-full.v1"}
```

O preflight Laravel foi executado novamente na VPS. postgres_tls passou de blocked para **verified/pg_stat_ssl_session_enabled**, com PostgreSQL18 e role mínima verificados. O status global permanece **blocked**, can_accept_real_orders=false/can_publish=false. Restam: domínio técnico, identidade Portal, contrato/pagamento, revogação independente, backup externo, aceite humano, publisher/rollback e revisão AppSec/LGPD. Nenhum gate foi alterado por flag fictícia.

## Testes de código e falhas corrigidas

HEAD funcional dd91486352bdc45b68e1b6289546759cc36360f5:11 check runs recuperados success (incluem push/PR duplicados, não11 suítes exclusivas). [Infraestrutura CI43810046212](https://github.com/rafhael100/imagine-sites/actions/runs/43810046212) construiu stack descartável, aplicou TLS por reload, provou verify-full/CA/hostname/plaintext e atualizou config do app sem mudar imagem; HTTPS/mTLS, SQL/RLS e restore passaram. Domínio e Laravel PHP8.3/8.4 também aprovados. Localmente:7 testes de inventário, compile Python, parse YAML e diff-check aprovados.

A primeira execução de infraestrutura falhou porque docker cp recusa rootfs read-only mesmo no destino tmpfs. Corrigido para escrita do script/CA pública exclusivamente no /tmp já autorizado, por stdin de docker exec; rootfs continua read-only. A nova execução passou antes da VPS. Nenhum pacote/serviço foi reinstalado na VPS.

## Continuação Portal e bloqueios comerciais

[Portal901](https://github.com/rafhael100/imagine-portal/pull/901), baseada na759, prepara fonte PostgreSQL nativa para o vínculo imutável de pagamento esperado e factory Sandbox por segredo montado. Corrige revalidação do vínculo após GET ao PSP. Esquema separado, não instalado na VPS; nenhuma ativação automática/route/provider substituído. Seu CI usa dados fictícios e resposta PSP sintética explicitamente identificada; não é conciliação operacional.

Não foram disponibilizados token Asaas Sandbox, produtor nativo homologado de pedidos/contratos, identidade/escopos mTLS Portal e fonte operacional independente de revogação. A aplicação Sites continua sem egress externo; o PSP pertence à autoridade Portal, não será ligado ao Sites abrindo internet indiscriminadamente. Não se buscou credencial do CRM/Performance/Portal alheio para contornar os bloqueios.

**Não há pedido fictício conciliado oficialmente, projeto provisionado por compra, prévia de cliente nem aceite humano operacional nesta rodada.** O navegador continua oferecendo apenas painel técnico privado e health. localhost do computador do usuário não aponta para a VPS; é necessário acesso privado autorizado e CA confiável. Nenhuma URL pública, DNS, cobrança ou site de cliente foi criado.

## Operação e recuperação

Os helpers prepare/enable/snapshot são de aplicação única e recusam material/snapshot existentes. Não repetir start, prepare ou enable cegamente no ambiente atualizado. Não executar down-v, reinstalar Postgres ou trocar a porta. Rotação futura: material novo com mesmos escopos/CA autorizada, permissões verificadas, reload e testes negativos; troca de CA demanda atualização controlada da confiança do app, sem fallback para prefer/plaintext.

Antes de qualquer nova fase, preservar o dump cifrado/HMAC e restore local já comprovados na482; documentar imagem/config/CA e IDs atuais. Falha de TLS deve corrigir confiança/certificado e manter acesso comercial fechado, jamais desativar criptografia. Rollback de app deve manter verify-full e CA verificada; não restaurar credenciais/revogações antigas sem revalidação atual. O HBA original e chaves permanecem privados; não publicá-los nem copiar para outros produtos.

**Backup externo ainda não comprovado:** falta destino privado aprovado, credencial segura, retenção/RPO/RTO e restauração observada a partir dessa cópia. Não enviar dump/chaves ao GitHub ou a armazenamento novo sem autorização. A restauração local anterior não é backup externo nem prova de recuperação de identidades/revogações operacionais inexistentes.

Próxima sequência: fonte nativa de pedidos e identidade Portal → credencial/conta Sandbox e confirmação independente de pagamento fictício → principal mTLS por empresa/revogação → provisionamento e prévia privada → revisão/aceite autenticados vinculados ao digest exato → revogação/backup externo/rollback. Enquanto isso, NO-GO permanece.
