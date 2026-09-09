ALTER TABLE relogios
  MODIFY COLUMN modelo ENUM('zkteco', 'facepro', 'dahua', 'outro') NOT NULL DEFAULT 'zkteco',
  ADD COLUMN tipo_protocolo ENUM('zkteco', 'dahua', 'outro') NOT NULL DEFAULT 'zkteco',
  ADD COLUMN device_url VARCHAR(255) NULL COMMENT 'URL base para API ISAPI, ex: http://192.168.100.144',
  ADD COLUMN device_user VARCHAR(80) NULL COMMENT 'Username para autenticação no dispositivo',
  ADD COLUMN device_password_enc VARCHAR(255) NULL COMMENT 'Password cifrada do dispositivo';
