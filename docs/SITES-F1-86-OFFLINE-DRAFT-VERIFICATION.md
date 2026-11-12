# F1-86 — Verificador offline independente do pacote de rascunho

O Imagine Sites já gerava pacotes privados de revisão, com HTML, manifesto, resumo e dossiê. O novo **verificador de disco**, `SyntheticPrivateDraftVerifier`, confere os arquivos gravados sem confiar no resultado em memória do gerador.

## Funcionamento

- Reabre um diretório de laboratório local e exige **exatamente** os quatro arquivos esperados: `index.html`, `release-manifest.json`, `internal-review-draft.json` e `internal-review-dossier.json`. Arquivos extras, faltantes, diretórios inesperados ou links simbólicos são rejeitados.
- Limita o tamanho dos arquivos e exige JSON com **a representação canônica original byte a byte**. Isso rejeita chaves duplicadas, mudanças de espaços, campos extras e versões ambíguas.
- Reconstitui o `ReleaseManifest`, confere o SHA-256 e tamanho do HTML, digest do bundle, referência do tenant/site/release, digest do dossiê, revisão de fluxo, estado de revisão pendente e todos os flags que impedem aprovação e publicação.
- Reexecuta a auditoria estrutural offline sobre o HTML, compara cada resultado ao checklist de QA e confere a lista exata dos itens editoriais pendentes.
- Nunca muda um arquivo e retorna somente metadados informativos, com `officialClientAcceptance=false` e `publishable=false`.

O comando Laravel `sites:verify-draft-fixture {product} {preset} {variant}` opera apenas em PHP CLI, `APP_ENV=testing` e `SITES_SYNTHETIC_VERIFY=1`. Seletores são listas fechadas: produtos `onepage`/`landing_page`, temas `classic`/`modern`/`warm` e variantes `basic`/`focused`. **Não recebe path, URL, código HTML ou credenciais do usuário.** Os diretórios devem ser gerados anteriormente pelo comando F1-72, também restrito ao laboratório.

## Testes

A suíte Laravel verifica as 12 combinações, hashes de origem e destino, não modificação dos arquivos, alteração de HTML, manifesto, resumo e dossiê, flags falsas modificadas, checksum recalculado com status de aprovação forjado, arquivo extra, symlink, opções inválidas e comando desligado por padrão. A matriz PHP 8.3/8.4 e o Docker CI exercitam o mesmo verificador.

## Limitação fundamental

**SHA-256 não autentica um cliente nem comprova que um humano aprovou.** Um agente que possa alterar todos os arquivos e seus hashes não fica impedido apenas por este verificador; a futura origem confiável do Portal, assinatura, revogação, trilha auditável, appsec independente e permissões reais continuam requisitos obrigatórios. Este código nunca transfere o pacote para Cloudflare, CRM, Portal ou outro serviço. Os dez gates de produção permanecem `BLOCKED`; sem merge automático, dados pessoais, DNS, VPS ou custos externos.
