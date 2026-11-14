# Sites P0 — Pré-homologação Leaseweb somente leitura

**Data:** 14/11/2026  
**Escopo:** preparação auditável para homologação de *staging* do Imagine Sites, compartilhando a VPS de desenvolvimento Leaseweb com serviços independentes e isolamento por contêiner. **Status NO-GO** para deploy, vendas, clientes, DNS, publicação e merge.

## Entregue nesta PR

`ops/homologation/leaseweb-readonly-preflight.py` é um diagnóstico **somente leitura** com biblioteca padrão Python 3. Reutiliza `ops/homologation/compose.yaml` F1-373 e `config/operational-readiness.json` sem alterá-los. Não instala software, não sobe contêineres, não acessa banco, credenciais, Cloudflare nem endpoints do Asaas.

O diagnóstico verifica:
- Política canônica `BLOCKED`, `non_operational=true` e os dez gates de produção explicitamente `false`; alterações para GO não são aceitas.
- Inventário exato de serviços `sites-php` e `sites-proxy`, sem entrada de Portal, CRM, workers ou banco de produção no Compose de ensaio.
- Porta exclusiva `127.0.0.1:18081` no Nginx; PHP-FPM sem porta pública, contêineres sem privilégios e com filesystem readonly, `cap_drop: ALL` e `no-new-privileges`.
- Rede exclusiva `sites-internal` para o PHP e rede de borda apenas para o proxy, com isolamento `internal=true`.
- `APP_ENV=staging`, `APP_DEBUG=false`, campos DB explicitamente **não provisionados** e nenhum flag de laboratório ativado por Compose.
- Opcionalmente, prova local **somente leitura** de Ubuntu 24.04, Docker Compose e daemon Docker disponíveis; não verifica outras versões do Ubuntu como elegíveis para este alvo contratado.

O script chama **somente** `docker compose config --format json`, para análise de configuração, injetando uma chave sintética e substituindo a chave existente no processo de leitura. Jamais imprime ou grava a configuração expandida, chave, usuário SQL, host privado, caminho de segredo, endereço IP do servidor, detalhes do daemon ou exceções. A saída é um JSON sanitizado de estados e códigos estáticos.

O comando **sempre retorna status 1 e `status=blocked`**, mesmo se toda a inspeção estrutural passar: configuração é evidência auxiliar, não autorização. Nunca conectar a saída à ativação de pagamento, site, publisher ou DNS.

## Execução no CI (não na VPS)

```bash
python3 tests/test_leaseweb_readonly_preflight.py
python3 ops/homologation/leaseweb-readonly-preflight.py --probe-host
```

A segunda linha termina com código 1 de propósito. O workflow `sites-p0-leaseweb-readonly-preflight.yml` usa Ubuntu 24.04, verifica Docker e Compose em modo leitura, testa cenários adversariais (porta 0.0.0.0, PHP público, privilégio, network aberta, APP_ENV production, banco real e feature flags), exige NO-GO e garante que canários de segredo não constem do relatório. Não cria artefatos públicos, não executa `docker compose up`, e não usa credenciais da VPS.

## Próxima sessão operacional com infraestrutura autorizada

**A infraestrutura Leaseweb e Cloudflare não está disponível para acesso direto nesta conversa.** Para testar a VPS real, um operador deve abrir uma sessão autorizada na VPS de homologação ou conectar um ambiente de execução com as permissões necessárias, mantendo chaves no vault do operador (nunca no GitHub público ou chat). A partir do checkout em branch revisada, executar **somente o diagnóstico de leitura**:

```bash
python3 ops/homologation/leaseweb-readonly-preflight.py --probe-host
```

O relatório da VPS deve ser analisado localmente sem log de credenciais. Resultado esperado é `blocked`. **Não** usar o compose efêmero de CI como um serviço público e não usar a porta localhost como prova de HTTPS externo. Não conectar serviços, criar banco nem alterar firewall/DNS sem plano aprovado, backup remoto restaurado, credenciais de menor privilégio e verificação de coexistência com outros contêineres na VPS.

## Gates que continuam obrigatoriamente bloqueados

1. IAM real Portal↔Sites por mTLS e segregação de serviços; rotação/vault; aprovação AppSec.
2. Pedido/contrato oficiais e liquidação confirmada no Asaas com tratamento de estorno, revogação e cancelamento.
3. Aceite expresso do titular pelo release e SHA-256 exatos, com histórico e consulta de revogação independente.
4. PostgreSQL18 persistente isolado, FORCE RLS, contas sem privilégios, outbox e restauração comprovada **fora** da VPS (não apenas GitHub CI).
5. Domínio técnico autorizado, TLS, publicador Cloudflare de menor privilégio, observação independente e rollback manual testado.
6. Revisão independente de privacidade/LGPD, segurança, custo e autorização formal de go-live.

Seguir issues [#33](https://github.com/rafhael100/imagine-sites/issues/33), [#34](https://github.com/rafhael100/imagine-sites/issues/34), [#35](https://github.com/rafhael100/imagine-sites/issues/35), [#37](https://github.com/rafhael100/imagine-sites/issues/37) e [#38](https://github.com/rafhael100/imagine-sites/issues/38).

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY / NO-CHARGE / NO-PUBLISH.** Esta entrega não prova execução ou conectividade na Leaseweb e não transforma flags verdes em autorização operacional.
