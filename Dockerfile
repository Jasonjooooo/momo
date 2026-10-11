FROM python:3.12-slim
WORKDIR /app
COPY server.py index.html manifest.json sw.js icon.svg ./
ENV MOMO_HOST=0.0.0.0 MOMO_PORT=8765 MOMO_DATA=/data TZ=Asia/Shanghai
VOLUME /data
EXPOSE 8765
CMD ["python3","-u","server.py"]
