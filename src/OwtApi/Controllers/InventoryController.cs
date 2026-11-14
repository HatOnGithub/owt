using System.Text.Json.Nodes;
using Microsoft.AspNetCore.Mvc;
using OwtApi.DTO;
using OwtApi.Services;

namespace OwtApi.Controllers;

/// <summary>
/// Private adapter API. Identity headers are trusted only after API-key authentication;
/// native object access is established by PHP before it calls this controller.
/// </summary>
[ApiController]
[Route("api/inventory")]
public sealed class InventoryController(IInventoryService service) : ControllerBase
{
    private Actor Actor()
    {
        int Number(string name) =>
            int.TryParse(Request.Headers[name], out var n) && n > 0
                ? n
                : throw new ArgumentException($"Missing {name}");
        bool Flag(string name) => Request.Headers[name] == "1";
        return new Actor(
            Number("X-Owt-Entity"),
            Number("X-Owt-User"),
            Flag("X-Owt-Read"),
            Flag("X-Owt-Manage"),
            Flag("X-Owt-Stock"),
            Flag("X-Owt-Admin"),
            Flag("X-Owt-History"),
            Flag("X-Owt-Use"),
            Request
                .Headers["X-Owt-Permissions"]
                .ToString()
                .Split(',', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries)
        );
    }

    [HttpGet]
    // Normal inventory reads omit history; its permission-checked query serves the history views.
    public IActionResult Get([FromQuery] bool includeHistory = false) =>
        Ok(service.State(Actor(), includeHistory));

    [HttpPost("{command}")]
    public IActionResult Command(string command, [FromBody] JsonObject data) =>
        Ok(new { result = service.Execute(Actor(), command, data) });
}
