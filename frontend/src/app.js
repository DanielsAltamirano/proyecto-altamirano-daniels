const API = '/api';

function alerta(msg) {
    const box = document.getElementById('alert');
    box.textContent = msg;
    box.style.display = 'block';
    setTimeout(() => box.style.display = 'none', 4000);
}

// 1. Verificar salud (Healthcheck)
async function verificarSalud() {
    try {
        const res = await fetch(`${API}/health`);
        const data = await res.json();
        const badge = document.getElementById('health-badge');
        if (data.status === 'ok') {
            badge.textContent = `Salud OK (DB: ${data.mariadb} | Cache: ${data.redis})`;
            badge.className = 'badge ok';
        } else {
            badge.textContent = 'Servicios degradados';
            badge.className = 'badge err';
        }
    } catch {
        document.getElementById('health-badge').textContent = 'Error de conexión';
        document.getElementById('health-badge').className = 'badge err';
    }
}

// 2. Cargar datos (GET) y actualizar métricas de Redis
async function cargarDatos() {
    const q = document.getElementById('buscar').value;
    const url = q ? `${API}/reservaciones?buscar=${encodeURIComponent(q)}` : `${API}/reservaciones`;
    
    try {
        const res = await fetch(url);
        const data = await res.json();
        
        document.getElementById('m-origen').textContent = data.origen || '-';
        document.getElementById('m-contador').textContent = data.contador_consultas || '0';
        document.getElementById('m-ttl').textContent = data.ttl_restante >= 0 ? `${data.ttl_restante} s` : 'Sin caché';

        const tbody = document.getElementById('tabla');
        tbody.innerHTML = '';
        
        (data.datos || []).forEach(r => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>#${r.id}</td>
                <td><strong>${r.espacio}</strong><br><small>${r.solicitante}</small></td>
                <td>${r.fecha_reservacion}<br><small>${r.hora_inicio} - ${r.hora_fin}</small></td>
                <td><span class="pill prio-${r.prioridad}">${r.prioridad}</span><br><span class="pill est-${r.estado}">${r.estado}</span></td>
                <td>
                    <button class="btn-sm btn-sec" onclick="cambiarEstado(${r.id}, '${r.estado === 'Confirmada' ? 'Cancelada' : 'Confirmada'}')">Estado</button>
                    <button class="btn-sm btn-danger" onclick="eliminar(${r.id})">Borrar</button>
                </td>
            `;
            tbody.appendChild(tr);
        });
    } catch (e) { console.error('Error al cargar', e); }
}

// 3. Crear Reservación (POST)
document.getElementById('form-res').addEventListener('submit', async (e) => {
    e.preventDefault();
    const payload = {
        espacio: document.getElementById('espacio').value,
        solicitante: document.getElementById('solicitante').value,
        correo_solicitante: document.getElementById('correo').value,
        fecha_reservacion: document.getElementById('fecha').value,
        prioridad: document.getElementById('prioridad').value,
        hora_inicio: document.getElementById('inicio').value,
        hora_fin: document.getElementById('fin').value,
        motivo: document.getElementById('motivo').value
    };

    const res = await fetch(`${API}/reservaciones`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    });
    
    if (res.ok) {
        alerta('Reservación guardada. Correo enviado a Mailpit.');
        document.getElementById('form-res').reset();
        cargarDatos();
    } else {
        const error = await res.json();
        alerta(error.error || 'Error al guardar');
    }
});

// 4. Cambiar estado (PUT)
window.cambiarEstado = async (id, estado) => {
    const res = await fetch(`${API}/reservaciones/${id}`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ estado })
    });
    if (res.ok) { alerta('Estado actualizado y caché invalidada.'); cargarDatos(); }
};

// 5. Eliminar (DELETE)
window.eliminar = async (id) => {
    if (!confirm('¿Seguro que deseas eliminar el registro #' + id + '?')) return;
    const res = await fetch(`${API}/reservaciones/${id}`, { method: 'DELETE' });
    if (res.ok) { alerta('Eliminada correctamente.'); cargarDatos(); }
};

// Inicializar
verificarSalud();
cargarDatos();