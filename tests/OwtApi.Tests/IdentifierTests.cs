using System.Text.Json.Nodes;
using Microsoft.Extensions.Options;
using OwtApi.DTO;
using OwtApi.Helpers;
using OwtApi.Repositories;
using OwtApi.Services;
using static OwtApi.Helpers.Json;

internal static class IdentifierTests
{
    public static int Run(string directory)
    {
        var checks = 0;
        void Check(bool valid, string message)
        {
            if (!valid)
                throw new Exception(message);
            checks++;
        }
        void Reject(Action action)
        {
            try
            {
                action();
            }
            catch (Exception error) when (error is ArgumentException or InvalidOperationException)
            {
                checks++;
                return;
            }
            throw new Exception("Expected invalid identifier/range rejection");
        }
        foreach (var length in new[] { 6, 8 })
        {
            var code = Codes.FromIndex(198, length);
            Check(code.Length == length && Codes.Valid(code), "Both lengths retain checksums");
            Check(Codes.New(length).Length == length, "Random generation supports both ranges");
            for (var pos = 0; pos < code.Length; pos++)
                foreach (var symbol in Codes.Alphabet.Where(c => c != code[pos]))
                {
                    var changed = code.ToCharArray();
                    changed[pos] = symbol;
                    Check(
                        !Codes.Valid(new string(changed)),
                        "Every single-symbol error is detected"
                    );
                }
            for (var pos = 0; pos < code.Length - 2; pos++)
                if (code[pos] != code[pos + 1])
                {
                    var changed = code.ToCharArray();
                    (changed[pos], changed[pos + 1]) = (changed[pos + 1], changed[pos]);
                    Check(!Codes.Valid(new string(changed)), "Payload swaps remain detectable");
                }
            Check(
                Codes.Valid(Codes.FromIndex(Codes.Capacity(length) - 1, length)),
                "Last payload included"
            );
        }
        Check(
            Codes.Capacity(8) == Codes.Capacity(6) * 31 * 31,
            "Eight-character capacity uses Int64"
        );
        Check(
            Codes.FromIndex(191, 8) == "22222872" && Codes.Valid("22222872"),
            "Shared numeric-looking eight-character checksum vector"
        );
        Check(Codes.Valid(Codes.FromIndex((long)int.MaxValue + 1, 8)), "Payloads above Int32 work");
        foreach (var invalid in new[] { "22222", "2222222", "222222222", "2222222I", "2222222O" })
            Check(!Codes.Valid(invalid), "Other lengths and ambiguous symbols are rejected");
        Reject(() => Codes.New(7));
        Reject(() => Codes.FromIndex(Codes.Capacity(6)));

        // Occupancy oracles model the actual 31^5 boundary without allocating millions
        // of fixture objects. Random collisions alone must never force early expansion.
        var last = Codes.FromIndex(Codes.Capacity(6) - 1);
        var almostFull = Codes.Next(
            length => length == 6 ? Codes.Capacity(6) - 1 : 0,
            code => code != last,
            cursor: Codes.Capacity(6) - 2,
            random: length => Codes.FromIndex(0, length)
        );
        Check(almostFull.Code == last && almostFull.Length == 6, "Last six-character slot is used");
        Check(almostFull.Cursor == 0, "Fallback wraps safely after the last payload");
        var expanded = Codes.Next(
            length => length == 6 ? Codes.Capacity(6) : 1,
            code => code.Length == 6 || code == Codes.FromIndex(0, 8),
            random: length => Codes.FromIndex(0, length)
        );
        Check(
            expanded.Length == 8 && expanded.Code == Codes.FromIndex(1, 8),
            "Full range expands and avoids long-ID collisions"
        );
        Reject(() => Codes.Next(Codes.Capacity, _ => true));
        var wrapped = Codes.Next(
            _ => 1,
            code => code == Codes.FromIndex(0),
            random: _ => Codes.FromIndex(0)
        );
        Check(
            wrapped.Code == Codes.FromIndex(1),
            "Repeated random collisions find an unoccupied six-character ID"
        );

        var repo = new StateRepository(
            Options.Create(new AppSettings { DataDirectory = directory })
        );
        var service = new InventoryService(repo);
        var actor = new Actor(96, 7, true, true, true, true);
        var shortCode = service.Execute(actor, "create", new JsonObject())!.GetValue<string>();
        Check(shortCode.Length == 6, "New entities start with six characters");
        // Simulate persisted post-exhaustion metadata; this is the same state written by Reserve.
        repo.Update(
            96,
            7,
            "seed-expanded-range",
            new JsonObject(),
            state =>
            {
                state.Map("identifier_allocation")["items"] = Object(
                    ("length", 8),
                    ("cursor", (long)int.MaxValue + 1)
                );
                state.Map("identifier_allocation")["physical_bins"] = Object(
                    ("length", 8),
                    ("cursor", 0L)
                );
                return true;
            }
        );
        var batch = service.Execute(actor, "bulk", Object(("count", 2)))!.GetValue<string>();
        var state = service.State(actor);
        Check(
            state["batches"]![batch]!["codes"]!
                .AsArray()
                .All(code => code!.GetValue<string>().Length == 8),
            "Expanded bulk allocations use eight characters"
        );
        Check(state.Map("items").ContainsKey(shortCode), "Old and expanded identifiers coexist");
        var bin = service.Execute(
            actor,
            "physical-bin-create",
            Object(("label", "Long bin"), ("warehouse_id", 1))
        )!;
        Check(
            bin.S("id").Length == 8 && Codes.Valid(bin.S("id")),
            "Physical bins support expanded IDs"
        );
        var pending = service.Execute(
            actor,
            "native-stage",
            Object(("action", "create"), ("fields", new JsonObject()))
        )!;
        var nativeCode = pending["result"]!.GetValue<string>();
        Check(nativeCode.Length == 8, "Native staging uses the expanded allocator");
        service.Execute(actor, "native-finish", Object(("id", pending.S("id"))));
        var reopened = new InventoryService(
            new StateRepository(Options.Create(new AppSettings { DataDirectory = directory }))
        );
        Check(
            reopened.State(actor).Map("items").ContainsKey(nativeCode),
            "Native finish preserves long IDs"
        );
        Check(
            reopened.Execute(actor, "create", new JsonObject())!.GetValue<string>().Length == 8,
            "Expansion survives restart with spare short slots"
        );
        Check(
            reopened
                .Execute(actor with { EntityId = 97 }, "create", new JsonObject())!
                .GetValue<string>()
                .Length == 6,
            "Expansion stays entity-scoped"
        );
        Check(
            reopened.State(actor)["identifier_allocation"]!["items"]!["cursor"]!.GetValue<long>()
                > int.MaxValue,
            "Allocator cursors survive persistence above Int32"
        );
        return checks;
    }
}
