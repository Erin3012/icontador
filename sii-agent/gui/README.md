# Interfaz Tkinter portable

`app.py` es el panel local; `../construir-portable.ps1` crea `build/portable/AgenteSII/AgenteSII.exe` con Node, Playwright y Chromium incluidos. El worker consulta Windows Credential Manager directamente y el paquete no contiene `.env` ni tokens.

**Cancelar tarea** cancela la fila seleccionada (o la activa si no hay selección), **Reiniciar** interrumpe y reencola la tarea activa, y **Mostrar navegador** habilita Chromium para la siguiente tarea. Cerrar la ventana cancela la tarea activa y finaliza el worker. Solo se procesa una tarea a la vez.

Al iniciar la ventana, Chromium queda abierto. Cada trabajo usa un contexto aislado y lo cierra al terminar para separar las sesiones de las empresas; el proceso de Chromium permanece listo para el siguiente.

Los registros se rotan en `logs/agente.log`. El endpoint de estado no reclama trabajos y solo devuelve resúmenes; no expone RUT, resultados privados ni claves.
