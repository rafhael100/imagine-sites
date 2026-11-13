F1-191 — Comparador de cores de marca na revisão Laravel

A personalização F1-190 pode ser analisada pela equipe Imagine na interface privada. O revisor oferece quatro escolhas fixas no motor: Tema original, Oceano, Ameixa e Floresta, para ambos produtos e os temas Classic/Modern/Warm. Nenhuma cor é recebida como CSS livre ou payload da IA.

A rota /_lab/sites/v1/brand-palette exige PHP CLI, APP_ENV=testing, IP loopback e flags SITES_SYNTHETIC_HTTP, SITES_SYNTHETIC_REVIEW_UI, SITES_SYNTHETIC_RICH_REVIEW e SITES_F191_BRAND_REVIEW. Apenas produto, dispositivo, preset e palette aparecem na query, todos em conjuntos fechados. Não há POST, editor público, sessão real, upload ou integração CRM.

A UI preserva produto, tema e dispositivo e exibe HTML com SHA-256 próprio e revisão interna pendente no iframe sandbox. Para as três paletas de laboratório mostra os contrastes de hero início/fim e CTA, que atendem o limite 4,5:1. A escolha não aprova versão nem altera contrato ou identidade visual do cliente.

Testes Laravel PHP8.3/8.4 abrangem 2 produtos × 3 temas × 4 paletas × 2 dispositivos (48 respostas), SHA, CSP/noindex/no-store, catálogo fechado, queries inválidas, flags ausentes, IP externo e rotas anteriores. OP01/02/04 e dez gates bloqueados. Sem main/deploy/VPS/DNS/custos.
