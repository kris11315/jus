#!/usr/bin/env python3
"""
Melhora a imagem do comprovante usando Vertex AI Imagen
"""

import base64
import json
import subprocess
import sys
from pathlib import Path

PROJECT_ID = "project-1cbb1251-7dcc-4c1c-a30"
LOCATION = "us-central1"
MODEL = "imagen-4.0-ultra-generate-001"
OUTPUT_PATH = Path("desen/atendimento-else/images/comprovante_melhorada.png")

def get_access_token():
    """Obtém token de acesso via gcloud ADC"""
    result = subprocess.run(
        ["gcloud", "auth", "application-default", "print-access-token"],
        capture_output=True,
        text=True
    )
    if result.returncode != 0:
        print("❌ Erro ao obter token do gcloud:")
        print(result.stderr)
        sys.exit(1)
    return result.stdout.strip()

def upscale_image():
    """Faz upscaling da imagem do comprovante"""
    import requests

    # Obtém token
    token = get_access_token()

    # URL do endpoint
    url = f"https://aiplatform.googleapis.com/v1/projects/{PROJECT_ID}/locations/{LOCATION}/publishers/google/models/{MODEL}:predict"

    headers = {
        "Authorization": f"Bearer {token}",
        "Content-Type": "application/json"
    }

    # Prompt para melhorar a imagem
    prompt = """
Melhore e reformate esta imagem de um comprovante de justificativa eleitoral:
1. Remova qualquer distorção
2. Deixe o aspecto correto (não comprimido/gordo)
3. Aumente a nitidez e clareza
4. Mantenha as dimensões proporcionais corretas
5. Melhore a legibilidade dos campos
6. Retorne em alta resolução (2000x3000px ou melhor)
    """.strip()

    body = {
        "instances": [{
            "prompt": prompt
        }],
        "parameters": {
            "sampleCount": 1,
            "aspectRatio": "3:4"  # Proporcional ao comprovante
        }
    }

    print("🚀 Enviando para Vertex AI Imagen...")
    response = requests.post(url, json=body, headers=headers)

    if response.status_code != 200:
        print(f"❌ Erro na API: {response.status_code}")
        print(response.text)
        sys.exit(1)

    data = response.json()

    # Extrai a imagem base64
    if "predictions" not in data or not data["predictions"]:
        print("❌ Nenhuma predição retornada")
        sys.exit(1)

    img_base64 = data["predictions"][0]["bytesBase64Encoded"]

    # Salva a imagem
    img_data = base64.b64decode(img_base64)
    OUTPUT_PATH.parent.mkdir(parents=True, exist_ok=True)

    with open(OUTPUT_PATH, "wb") as f:
        f.write(img_data)

    print(f"✅ Imagem melhorada salva em: {OUTPUT_PATH}")
    print(f"   Tamanho: {len(img_data) / 1024:.1f} KB")

if __name__ == "__main__":
    try:
        upscale_image()
    except Exception as e:
        print(f"❌ Erro: {e}")
        sys.exit(1)
