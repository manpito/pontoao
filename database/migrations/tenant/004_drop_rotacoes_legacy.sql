-- Migration 004: Remove legacy rotation schema (replaced by escala model)
-- Pre-requisito: tabelas vazias (verificado no tenant_004_ftl)
-- Rollback: re-apply 002_rotacoes.sql

DROP TABLE IF EXISTS funcionario_rotacao;
DROP TABLE IF EXISTS rotacao_fases;
DROP TABLE IF EXISTS rotacoes;
