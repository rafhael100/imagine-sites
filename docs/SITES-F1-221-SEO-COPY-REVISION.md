# F1-221 — Novo aceite interno obrigatório após edição do título ou descrição SEO

F1-220 permite que título e descrição de metadados sejam diferentes do H1/hero. Esta entrega cria `SyntheticSeoCopyRevisionPlan::begin` para que qualquer mudança **explícita** nesses dois campos gere um novo rascunho, SHA-256 e releaseRef, sem reaproveitar a aprovação anterior. Um par de campos que produz o mesmo HTML não cria uma falsa versão.

A operação recebe a versão anteriormente revisada em estado AWAITING_PORTAL_ACCEPTANCE, revisão esperada, HTML original e SHA-256, releaseRef anterior e novo, campos opcionais e o rótulo sintético imagine_reviewer. Recalcula bytes originais, valida os campos pelo SiteBrief, renderiza o novo HTML e executa o inspetor SEO, a auditoria de HTML inerte, QA e o manifesto privado. A versão original não muda; o workflow novo volta a DRAFTING com `artifactDigest=null`, exigindo revisão independente.

Produto, marca, H1, hero, benefícios, FAQ, soluções, etapas, layout, menu, tema, paleta, contexto do tenant e modo social permanecem os mesmos. As tags OG/Twitter opcionais seguem o novo conteúdo SEO. Alterar só título, só descrição ou limpar ambos para voltar ao padrão são mudanças legítimas e versionadas. Tentativas com URLs, HTML bruto, papel fictício agent, revisão obsoleta, conteúdo adulterado, release repetido ou ausência de diferença real são negadas.

O teste F1-221 entra no gate principal PHP8.3/8.4 e cobre 12 variantes e testes adversariais. Todos os métodos de indexação, publicação, escrita no Imagine SEO Engine e aceite oficial retornam false. O rótulo imagine_reviewer ainda NÃO autentica pessoa; não há rede, cliente, lead, CRM ou deploy. OP01/02/04 e dez gates de produção permanecem bloqueados.
