-- Migration: Remove technician_id foreign key constraint from availability table
-- Run this in phpMyAdmin or MySQL CLI

ALTER TABLE `availability`
  DROP FOREIGN KEY `availability_ibfk_1`;
