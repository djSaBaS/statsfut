-- Tabla usuarios
CREATE TABLE usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario VARCHAR(50) UNIQUE,
    clave VARCHAR(255),
    equipo_nombre VARCHAR(100),
    equipo_escudo VARCHAR(255) DEFAULT NULL
);

-- Tabla partidos
CREATE TABLE partidos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT,
    fecha DATETIME,
    equipo_local VARCHAR(100),
    equipo_visitante VARCHAR(100),
    estado ENUM('en_curso','finalizado') DEFAULT 'en_curso',
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
);

-- Tabla estadisticas
CREATE TABLE estadisticas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    partido_id INT,
    equipo ENUM('local','visitante'),
    pases INT DEFAULT 0,
    banda INT DEFAULT 0,
    corner INT DEFAULT 0,
    tiro INT DEFAULT 0,
    gol INT DEFAULT 0,
    pases_consecutivos_actual INT DEFAULT 0,
    pases_consecutivos_maximo INT DEFAULT 0,
    FOREIGN KEY (partido_id) REFERENCES partidos(id)
);
