# F1-257 — Comparação de pacotes privados antes/depois do CTA

- Além de conferir os dois HTMLs, o novo verificador confere os quatro arquivos canônicos de cada versão: `index.html`, dossiê de revisão, resumo de draft e manifesto de release.
- Reexecuta o renderizador e as verificações de integridade SHA-256, sessão pendente e `noindex`, comparando apenas alterações autorizadas no botão secundário e seu CSS fixo.
- A evidência final contém hash de correlação do escopo e hashes de cada arquivo, sem publicar os identificadores de empresa/site em claro.
- Impede pacotes cruzados, arquivos extras, alterações de SEO/texto/tema, manifesto modificado e tentativas de trocar aprovação ou publicação.
- Testes negativos PHP executados na matriz 8.3/8.4 com cenários inteiramente sintéticos.

Sem dados reais, operação Cloudflare, CRM, servidor, conta, API ou autorização de deploy; os dez gates de produção continuam `BLOCKED`. PR exclusivamente draft.