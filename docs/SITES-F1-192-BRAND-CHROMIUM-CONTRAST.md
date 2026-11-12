F1-192 — Cores de marca renderizadas e contrastes verificados no Chromium

Depois da validação de luminância no PHP e da interface Laravel da F1-191, a suíte F1-192 abre a página estática em Chromium real e confirma os valores CSS efetivamente aplicados ao gradiente e ao botão, em vez de depender apenas da serialização HTML.

O teste gera dezoito HTMLs sintéticos: 2 produtos x 3 temas x 3 paletas de marca fixas. Cada arquivo é privado, não publicável, com SHA-256 e manifesto canônico. O Chromium, já instalado no runner, recebe os arquivos diretamente por CDP em loopback, sem web server. Para cada arquivo testa 1365px, 390px e 320px, totalizando 54 inspeções.

O CDP verifica que as duas cores calculadas do gradiente correspondem às do manifesto, que texto do hero e sobrancelha aparecem em branco, e que o botão utiliza exatamente fundo e texto aprovados. Contraste real computado no Chromium deve atingir 4,5:1 nos dois extremos do gradiente e no CTA. Também exige sem overflow, sem formulários/scripts/URLs externas, com noindex e CSP presentes.

Isto é evidência automatizada desses elementos e não homologação WCAG integral nem identidade visual assinada. Nenhum screenshot ou dado real é enviado. OP01/02/04, 10 gates, Portal/CRM operacional e deploy continuam bloqueados.
