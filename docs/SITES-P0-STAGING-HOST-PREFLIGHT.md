# P0 Imagine Sites — pré-verificação local e segura da VPS de homologação

**Status: preparação; NÃO é homologação, autorização de deploy nem inventário de clientes.**

Esta ferramenta foi criada para reduzir o trabalho manual da pendência [SITES-GATE #38](https://github.com/rafhael100/imagine-sites/issues/38). É executada **no próprio host** por quem já possui acesso autorizado e coleta apenas requisitos técnicos locais, sem conexões com Leaseweb, Cloudflare, CRM ou Portal.

## O que é observado

- Sistema Linux/Ubuntu e versão (com indicação se corresponde ao padrão candidato Ubuntu 24.04), arquitetura, CPUs, memória disponível/total e espaço livre no diretório que o operador selecionar.
- Existência de comandos locais: Docker CLI, Docker Compose CLI, Git, PHP, Composer e Python, incluindo **somente números de versão sanitizados**, nunca stdout/stderr completo.
- Se a pasta de trabalho indicada existe, se é diretório real (não symlink), se pertence ao UID efetivo e se grupo/outros possuem acesso.
- Se informado `--repo`, lê **somente** `config/operational-readiness.json` e resume status, número de gates e quantos estão falsos. Nunca modifica o arquivo.
- Uma lista fixa das revisões e homologações humanas que ainda faltam.

**Não são coletados:** hostname, IP público/privado, nomes de usuário, arquivos `.env`, certificados, senhas, tokens, volumes de cliente, bancos de dados, logs, processos, metadados do Docker daemon ou diretórios fora dos parâmetros especificados. O script não chama serviços de nuvem, não sobe contêineres e não modifica DNS.

## Utilização pela pessoa autorizada

Depois de obter o código por um checkout GitHub autorizado, no terminal SSH do Ubuntu 24.04, em uma pasta de trabalho **existente**:

```bash
python3 ci/p0_staging_host_readonly_probe.py \
  --workspace /CAMINHO/REAL/DO/WORKSPACE \
  --repo /CAMINHO/DO/REPOSITORIO/IMAGINE-SITES
```

Se não houver checkout local do repositório, omita `--repo`. Para formatar o relatório na tela:

```bash
python3 ci/p0_staging_host_readonly_probe.py --workspace . | python3 -m json.tool
```

As consultas `--version` dos CLIs locais são executadas com timeout de cinco segundos e sem shell. Elas não contatam intencionalmente o Docker daemon, mas o administrador deve executar a ferramenta **somente em host confiável**, pois binários instalados no PATH são código local do operador. O relatório omite sua saída bruta e preserva apenas a versão numérica. A ferramenta não verifica portas abertas, DNS, rede pública nem endpoints reais; essas verificações exigem aprovação e ambiente próprio.

A saída JSON tem sempre `status="BLOCKED_PENDING_OPERATOR_AND_SECURITY_REVIEW"`, `goLiveAuthorized=false`, `homologationComplete=false` e `liveEndpointTested=false`. O retorno 0 do comando significa **somente que o relatório foi produzido**, jamais que o host está apto para produção. Um campo de requisito positivo NÃO libera gates.

## Como usar o resultado na homologação

A revisão do responsável deve continuar com isolamento por contêiner, configuração segura de Postgres/RLS/backup, autenticação entre Portal↔Sites, n8n/Agents, armazenamento/CRM, certificados e DNS consentidos e checagens independentes de publicação Cloudflare. Nenhum desses passos é feito por este probe. A issue #38 e as issues OP01–OP05 (#33–#37) continuam abertas, além da proteção/revisão GitHub #281.

O workflow `.github/workflows/sites-p0-staging-host-preflight.yml` testa código e sanitização em um runner descartável do GitHub; **não testa a VPS contratada pelo usuário**. O relatório não é enviado para terceiros nem utilizado para alteração de planos, domínio ou infraestrutura.

Esta ferramenta é compatível com o objetivo de começar com VPS central compartilhada e serviços isolados em containers, mas não valida essa arquitetura até que um ambiente autorizado exista.
