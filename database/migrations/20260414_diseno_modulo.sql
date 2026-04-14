CREATE TABLE IF NOT EXISTS disenos_archivos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre_original VARCHAR(255) NOT NULL,
    ruta_archivo VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) DEFAULT NULL,
    extension VARCHAR(15) DEFAULT NULL,
    tamano_bytes BIGINT DEFAULT 0,
    usuario_id INT DEFAULT NULL,
    pedido_id INT DEFAULT NULL,
    remision_id INT DEFAULT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_usuario_id (usuario_id),
    INDEX idx_pedido_id (pedido_id),
    INDEX idx_remision_id (remision_id),
    INDEX idx_creado_en (creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
