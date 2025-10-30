INSERT INTO cidades (nome, sigla_uf)
VALUES ('Tijucas', 'SC')
ON DUPLICATE KEY UPDATE nome = VALUES(nome), sigla_uf = VALUES(sigla_uf);

INSERT INTO bairros (cidade_id, nome)
SELECT c.id, 'Centro'
FROM cidades c
WHERE c.nome = 'Tijucas' AND c.sigla_uf = 'SC'
ON DUPLICATE KEY UPDATE nome = VALUES(nome);
