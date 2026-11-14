# F1-379 — PostgreSQL 18 com TLS verificado por CA em contêineres de CI

**Estado:** ensaio criptográfico real de transporte em CI, nunca aplicado à VPS nem a qualquer cliente. Continua DRAFT/NO-GO.

## Objetivo

A F1-376 validou PHP-FPM/Laravel em rede interna com PostgreSQL 18 e role restrita, mas o diagnóstico ainda apontava `postgres_tls=blocked`. A F1-379 exercita essa fronteira em contêineres descartáveis, verificando **criptografia TLS ativa e autenticidade do certificado do servidor**.

O overlay `ops/homologation/compose.ci-tls.yaml` só pode ser montado com opt-in `SITES_F379_TLS_LAB=1`, caminho temporário de certificados e sobreposição dos Compose base e PG18 fictício. Um workflow do GitHub Actions gera uma nova CA e um certificado de servidor **a cada execução**, com SAN `sites-db`. A chave privada da CA não é montada em nenhum contêiner nem publicada; a chave do servidor é montada só no banco, com permissões restritas ao UID PostgreSQL. O certificado público da CA é montado somente leitura no PHP-FPM.

O PostgreSQL 18 inicia com `ssl=on`, `ssl_min_protocol_version=TLSv1.2` e `pg_hba.conf` próprio do CI: **conexões TCP sem TLS são explicitamente rejeitadas**. A conta SQL fictícia não é superusuária, e o banco continua sem porta publicada. O cliente PHP define `DB_SSLMODE=verify-full`, `PGSSLROOTCERT` e `DB_SSLROOTCERT` na configuração de laboratório. O Nginx continua acessível somente pelo `127.0.0.1:18081` do runner.

## Evidências automáticas

- Cliente PDO conectado à conta SQL restrita pela rede Docker e `sslmode=verify-full`, usando a CA efêmera e nome de host `sites-db` presente no certificado.
- Consulta `pg_stat_ssl` identifica sessão criptografada, PostgreSQL 18 e banco esperado.
- Tentativa com **outra CA** não confiável deve falhar.
- Tentativa `sslmode=disable` deve falhar pelo `hostnossl ... reject` do PostgreSQL.
- Comando Laravel `sites:homologation-preflight` verifica identidade, permissões e TLS da sessão. Os campos comerciais/segurança continuam bloqueados: `commercial_contract`, `portal_service_identity`, `backup_restore`, `independent_revocation`, `customer_approval`, `release_publisher`, `appsec_lgpd`.
- Nenhuma rota de admissão pública é registrada; o único endpoint HTTP de saúde é `/up`.
- Os três contêineres e os arquivos de CA/chaves são destruídos ao final do job, inclusive após erro.

## Limitações

O fato de uma sessão PostgreSQL estar TLS e o certificado validar **não** significa que a infraestrutura operacional foi homologada. A CA, chave e papel SQL aqui são fictícios/efêmeros. Ainda não existe autoridade de certificados aprovada, política de rotação, cofre de segredos, credenciais por serviço, mTLS, observabilidade/backup externo, RPO/RTO, IAM Portal, gateway de pagamento real ou autorização de contrato/cliente. Esse workflow não gera nem exporta credenciais para uso na VPS.

**PR empilhado, DRAFT/NO-GO.** Não copiar `ci-pg_hba.conf` para bancos de clientes: a linha de acesso local `trust` existe somente para bootstrap de contêiner de teste. Sem merge, deploy, DNS, Cloudflare ou gasto externo.
