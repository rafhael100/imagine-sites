# F1-344 — nomes de projeto, rede e volume descartáveis fixados no laboratório

**Somente GitHub Actions e testes locais sintéticos. Não é configuração de produção Leaseweb.**

## Risco coberto

Mesmo sem portas públicas e com rede `internal: true`, um Compose modificado poderia usar o nome de **outro projeto**, anexar-se a rede externa ou apontar para um volume persistente pré-existente. Isso abriria risco de interferir com dados ou serviços que não pertencem ao laboratório.

## Verificação adicional

`ci/f74_docker_lab_boundary.py`, executado antes de Docker build/start no workflow F1-83, exige:

- Nome de projeto `imagine-sites-lab`.
- Uma única rede `sites-lab-isolated`, de nome renderizado `imagine-sites-lab_sites-lab-isolated`, `internal: true`, driver bridge padrão ou omitido, sem `external`, sem modo `attachable` e sem campos extras. O JSON renderizado do Docker Compose pode incluir um objeto `ipam` padrão; somente o objeto vazio/driver `default` sem redes ou gateways personalizados é aceito.
- Um único volume `sites-lab-data`, com nome renderizado `imagine-sites-lab_sites-lab-data`, driver local padrão ou omitido, sem `external`, `driver_opts` ou nomes apontando para outro stack.

A forma renderizada pode omitir os campos `name` internos quando usada apenas no fixture de testes, mas jamais aceitar outro nome. Há mutações negativas simulando rede `macvlan`, IPAM com sub-rede forçada ou driver arbitrário, rede anexável, nome de outra aplicação, volume chamado `production_db`, volume externo, driver personalizado e novo volume não previsto.

## Limitações

Essas políticas evitam configurações evidentemente indevidas **no arquivo de laboratório inspecionado**. Não atestam isolamento do Docker daemon, do host, de um volume já existente com nome idêntico nem dos serviços reais na VPS. A implantação verdadeira necessita nomes de projeto e persistência isolada formalmente definidos, supervisão humana e testes operacionais independentes. `dev/main` e infraestrutura não são modificadas. Os gates #281 e OP01–OP05 permanecem bloqueados.
