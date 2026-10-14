CREATE DATABASE IF NOT EXISTS reservaciones_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE reservaciones_db;

CREATE TABLE IF NOT EXISTS reservaciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    espacio VARCHAR(100) NOT NULL,
    solicitante VARCHAR(100) NOT NULL,
    correo_solicitante VARCHAR(120) NOT NULL,
    fecha_reservacion DATE NOT NULL,
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    prioridad ENUM('Baja', 'Media', 'Alta') NOT NULL DEFAULT 'Media',
    estado ENUM('Pendiente', 'Confirmada', 'Cancelada') NOT NULL DEFAULT 'Confirmada',
    motivo VARCHAR(255) NOT NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_horario CHECK (hora_fin > hora_inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO reservaciones (espacio, solicitante, correo_solicitante, fecha_reservacion, hora_inicio, hora_fin, prioridad, estado, motivo) VALUES
('Laboratorio de Redes Cisco', 'Daniels Altamirano', 'daniels@itmorelia.edu.mx', '2026-10-20', '09:00:00', '11:00:00', 'Alta', 'Confirmada', 'Práctica de configuración de VLANs y enrutamiento'),
('Sala de Juntas A - Edificio K', 'Pedro Hernandez', 'Pedro.hernandez@itmorelia.edu.mx', '2026-10-21', '12:00:00', '13:30:00', 'Media', 'Confirmada', 'Reunión de seguimiento de arquitectura de software'),
('Auditorio Heber Soto', 'Juan Jesus Ponce', 'juan.ponce@itmorelia.edu.mx', '2026-10-22', '16:00:00', '18:00:00', 'Baja', 'Pendiente', 'Presentación de proyectos de sistemas distribuidos');