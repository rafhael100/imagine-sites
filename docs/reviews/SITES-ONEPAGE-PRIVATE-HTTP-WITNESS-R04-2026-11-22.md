# OnePage R04 — leitura de HTML efetivamente servido por HTTP loopback (NO-GO)

22/11/2026. Branch isolada sobre Sites #607 HEAD fixa `4f12e16c6f9b39fcf4db8848e98842bcd1e23e88`. Sites #541/#542 são históricas; Portal #976/#1003 são contrato/default deny. OnePage #605 é uma trilha SQL distinta, sem qualquer modificação. Landing #580, Portal #1087 e AI Agents #490 não foram modificados.

## Lacuna incremental

R03 validou os bytes que uma testemunha independente **declara** ter observado, mas esses bytes vieram de uma closure em memória. Faltava um teste com transporte HTTP que trouxesse o corpo efetivamente retornado por um processo servidor separado, comparasse o SHA e exercitasse o leitor final já existente sem reimplementar parser, recibo nem renderizador.

## Recorte executável e limitações

- Reutiliza os mesmos artefatos OnePage fictícios da R03 e o renderizador `LocalSiteRendering`, expondo somente o preparo da fixture para R04 (os 42 testes R03 já verdes não são executados novamente nessa etapa).
- Um servidor PHP embutido nasce em processo descartável, exclusivamente `127.0.0.1`, porta efêmera e diretório de acesso 0700; retorna os bytes HTML por GET com `Cache-Control: no-store` e `Content-Type: text/html`. O teste obtém os **bytes do corpo HTTP**, não uma cópia de `htmlSha256` do recibo.
- O cookie e a regra do endpoint são *inteiramente fictícios*. Não representam MFA, sessão/identidade corporativa nativa ou uma decisão do Portal; não fornecem autoridade a Sites.
- Após capturar o corpo privado, o ensaio injeta os bytes na porta R03 `currentPresentation` e usa o leitor existente `OnepageExactFinalAcceptanceReadCandidate`. A metadata de apresentação, confirmação, revisão e clique é sintética; nenhuma fonte oficial é presumida.
- Negativas independentes: HTTP 403 sem sessão e com sessão de outro comprador, 404 para outro caminho, falha do leitor para outro comprador, alteração efetiva dos bytes HTTP mantendo o SHA declarado, **mudança do corpo no servidor entre os dois GETs da mesma decisão**, versão HTML B após clique da versão A, e sessão fictícia B negada depois de um novo recibo B.
- Recibo de versão B apenas com clique e SHA próprios, leitura HTTP B e `canPublish=false`. Não existe navegador real, transporte mTLS, writer jurídico, armazenamento de aceite, OIDC nem publicação.

CI isolada: PHP 8.3/8.4, sem credenciais, sem chamada OpenAI/Replit/PSP, sem DNS/VPS/Cloudflare, sem ação publicitária, merge, publicação ou aquisição. O processo e os arquivos descartáveis são limpos em `finally`. GitHub workflow com permissão apenas `contents: read`, branch/base/HEAD fixadas. Registrar CI final pela SHA antes de anunciar sucesso.

**Próxima dependência não demonstrada:** atestar no Portal a fonte operacional da revisão imutável, confirmação de direitos pelo comprador, aprovação/revogação distinta e prova de sessão/GET no navegador do mesmo pedido; worker real OnePage só com rota, identidade, modelo, escopo e egress específicos e previamente aprovados. Até lá, Sites #38 e publicação permanecem NO-GO.