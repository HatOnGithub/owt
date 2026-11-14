FROM node:24-alpine AS frontend
WORKDIR /frontend
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY frontend/ ./
RUN npm run build

FROM scratch AS dolibarr-module
COPY dolibarr/owt/ /owt/
COPY --from=frontend /frontend/dist/owt/browser/ /owt/spa/
COPY --from=frontend /frontend/dist/owt/3rdpartylicenses.txt /owt/spa/3rdpartylicenses.txt

FROM mcr.microsoft.com/dotnet/sdk:10.0 AS build
WORKDIR /source
COPY LICENSE ./
COPY src/OwtApi/OwtApi.csproj src/OwtApi/
RUN dotnet restore src/OwtApi/OwtApi.csproj
COPY src/OwtApi/ src/OwtApi/
RUN dotnet publish src/OwtApi/OwtApi.csproj -c Release -o /app --no-restore /p:UseAppHost=false

FROM mcr.microsoft.com/dotnet/aspnet:10.0 AS runtime
WORKDIR /app
COPY --from=build /app/ ./
RUN mkdir -p /data && chown app:app /data
ENV ASPNETCORE_HTTP_PORTS=8080 \
    AppSettings__DataDirectory=/data
USER app
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 CMD ["dotnet", "OwtApi.dll", "--healthcheck"]
ENTRYPOINT ["dotnet", "OwtApi.dll"]
