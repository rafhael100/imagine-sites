# Imagine Sites — renderização de um briefing local privado

## Fluxo atendido

O registro privado de artefatos recebe HTML pronto, com digest e tamanho exatos. O projeto já possui renderer e comandos Artisan de laboratório, mas esses comandos consomem fixtures fixas do repositório. Não havia uma entrada reutilizável para fornecer um briefing local privado ao mesmo renderer e gerar um arquivo novo para o registro.

A branch `feature/sites-local-artifact-rendering-2026-11-18` parte da PR #503 / `48d190f22ef9000dc52db55cc6b56510df6f7624`. A nova entrada `ops/release-candidate/render.php` fecha essa etapa local. Ela reutiliza `SiteBrief`, `StaticSiteRenderer` e `InertPreviewAudit`; não substitui o renderer, muda seus bytes ou remove seus avisos.

## Entrada e resultado

O comando explícito recebe um arquivo de briefing e um destino absoluto para um novo `index.html`. O JSON contém exatamente os seis campos já existentes: `productCode`, `brand`, `headline`, `description`, `callToAction` e `benefits`. O limite é de 32768 bytes; campos duplicados, extras, tipos indevidos e benefícios que não sejam uma lista são recusados. A validação textual continua em `SiteBrief`, preservando seu tratamento de Unicode e quebras de linha.

Essa é uma representação local dos campos do renderer, sem referência de empresa, pedido, contrato ou revisão Portal. O hash do insumo identifica os bytes lidos; não atesta revisão humana ou origem oficial.

A resposta sanitizada tem sete campos: `schema=SITES_LOCAL_RENDERING_RESULT_V1`, `status=rendered`, `inputSha256`, `artifactDigest`, `sizeBytes`, `canPublish=false` e `canAcceptRealOrders=false`. Não imprime o briefing, HTML ou caminhos privados. A recusa externa é somente `LOCAL_RENDER_DENIED`, sem cadeia de erro privada.

## Custódia e consumo

O arquivo de entrada deve ser privado, regular e com um único link, usando o leitor existente de preparação de releases. A CLI confere novamente bytes e identidade antes da gravação. O diretório de saída deve existir, ser canônico, pertencer ao usuário efetivo e ter modo 0700. O arquivo é criado exclusivamente em modo 0600; um destino preexistente é recusado.

A implementação confere ancestrais, identidade do arquivo aberto e do pathname, bytes escritos e sincronização. Não repara permissões, sobrescreve ou remove um destino. Uma falha de I/O pode deixar a saída exclusiva parcial ou completa: erro não promete rollback nem autoriza repetir sobre o mesmo arquivo.

O HTML gerado pode ser fornecido intacto ao caminho de entrada já exigido por `register.php`. Seu digest e tamanho alimentam o manifesto de registro existente; a origem local não substitui a autorização SQL desse comando. O ensaio integrado usará a CLI real de renderização antes de percorrer registro, revisão, associação e preparação já existentes, com atores separados.

## Verificação

**Validação local aprovada: 137 checks do serviço e 87 da CLI.** Ambos os produtos foram exercitados com bytes iguais ao renderer direto, Unicode/CRLF/NFD, limites, escaping, parsing estrito, arquivos privados e recusa de sobrescrita. Os positivos CLI usam somente diretórios temporários próprios. Trocas observadas de bytes, inode ou modo entre leitura e escrita são recusadas antes da criação do output, preservando o input modificado e o sentinel.

Lint, YAML, pins, sete opt-ins, todos os blocos shell, whitespace e revisões independentes foram aprovados. O safety guard passou em cópia de fontes e manteve os dez gates `BLOCKED`. Um smoke independente executou o exemplo pela CLI real e confirmou os 4724 bytes, digest/tamanho, modo 0600 e briefing preservado.

A fixture PostgreSQL substitui sua geração ad hoc por esta entrada explícita. Prevê **quatro verificações novas, 58 no registro e 716 no conjunto integrado**: sete campos exatos de saída; bytes/digest/tamanho/custódia; preservação de input/mounts/SQL; e recusa de reexecução sem sobrescrita. O registro recebe o arquivo realmente produzido, enquanto o renderer direto serve apenas de referência. Renderização, registro, preparação e verificação são exercitados pelas CLIs; as transições editoriais e a associação desse mesmo artefato usam as APIs PHP existentes. A cadeia e seus atores permanecem os mesmos.

O workflow `.github/workflows/sites-local-artifact-rendering.yml` fixa draft, repositório, branch e SHA da base. Mantém PHP 8.3/8.4, os sete opt-ins existentes e o laboratório descartável. A renderização em si não abre banco, rede ou aplicação Laravel. **CI desta HEAD pendente: 4/58/716 são previsões até a execução real.** O workflow exige o marcador do novo ensaio e usa `pipefail` para preservar falhas do runner e de sua limpeza.

## Base validada e limites

A PR #503 foi validada com **19/19 check-runs SUCCESS** no [run 44234103268](https://github.com/rafhael100/imagine-sites/actions/runs/44234103268), com **312 verificações HTTP, 386 SQL do workspace em PHP 8.3/8.4 e PHPUnit 141 testes/1310 assertions/27 skips**. As capturas desktop/mobile e comparação corrigida foram revisadas. O [relatório da comparação](SITES-COMPARISON-RECOVERY-2026-11-18.md) mantém os jobs, checksum do artifact e confirmação de limpeza.

Os comandos Artisan anteriores permanecem com sua finalidade. A nova CLI continua usando o renderer demonstrativo e seus avisos; não promove `sites_preview`, importa briefing oficial ou executa geração/publicação operacional. O [contrato de integração central](SITES-NEXT-INTEGRATION-2026-11-18.md) continua uma dependência separada. Nenhum schema, grant, provider, staging ou dado real é alterado, e os dez gates permanecem `BLOCKED`.
