using System.Security.Cryptography;
using System.Text.Json.Nodes;
using System.Text.RegularExpressions;
using OwtApi.Helpers;

namespace OwtApi.Services;

/// <summary>
/// QR identity/checksum contract shared with PHP and TypeScript. Legacy formats stay readable;
/// a valid identifier never grants access to its record.
/// </summary>
public static partial class Codes
{
    public const string Alphabet = "23456789ABCDEFGHJKMNPQRSTUVWXYZ";

    public static char Check(string payload)
    {
        if (payload.Length is not (5 or 7) || payload.Any(c => !Alphabet.Contains(c)))
            throw new ArgumentException("Invalid short QR payload");
        var state = 0;
        foreach (var c in payload)
            state = (state * 3 + Alphabet.IndexOf(c) + 1) % 31;
        return Alphabet[state];
    }

    public static bool Valid(string code) =>
        code.Length is 6 or 8
            ? code[..^1].All(Alphabet.Contains) && Check(code[..^1]) == code[^1]
            : Legacy().IsMatch(code);

    public static string New(int length = 6)
    {
        _ = Capacity(length);
        var payload = string.Concat(
            Enumerable
                .Range(0, length - 1)
                .Select(_ => Alphabet[RandomNumberGenerator.GetInt32(31)])
        );
        return payload + Check(payload);
    }

    /// <summary>The final character is a checksum, not an additional independent symbol.</summary>
    public static long Capacity(int length) =>
        length switch
        {
            6 => 28_629_151L, // 31^5
            8 => 27_512_614_111L, // 31^7; deliberately exceeds Int32.
            _ => throw new ArgumentException("Identifier length must be 6 or 8"),
        };

    /// <summary>Enumerate the payload space without omitting or repeating any valid code.</summary>
    public static string FromIndex(long index, int length = 6)
    {
        if (index < 0 || index >= Capacity(length))
            throw new ArgumentException("Identifier index outside its range");
        var payload = new char[length - 1];
        for (var i = payload.Length - 1; i >= 0; i--)
        {
            payload[i] = Alphabet[(int)(index % 31)];
            index /= 31;
        }
        var text = new string(payload);
        return text + Check(text);
    }

    /// <summary>
    /// Allocate against an occupied namespace. Random collisions never prove exhaustion:
    /// after bounded random attempts, walk the remaining payloads deterministically.
    /// The count callback must distinguish valid codes of each length when at capacity.
    /// Callers serialize allocation and insertion in the same repository transaction.
    /// </summary>
    public static (string Code, int Length, long Cursor) Next(
        Func<int, long> count,
        Func<string, bool> exists,
        int length = 6,
        long cursor = 0,
        Func<int, string>? random = null
    )
    {
        var capacity = Capacity(length);
        if (count(length) >= capacity)
        {
            if (length == 8)
                throw new InvalidOperationException("Identifier range exhausted");
            return Next(count, exists, 8, 0, random);
        }
        if (cursor < 0 || cursor >= capacity)
            throw new InvalidDataException("Invalid identifier allocation cursor");
        random ??= New;
        for (var attempt = 0; attempt < 32; attempt++)
        {
            var code = random(length);
            if (!exists(code))
                return (code, length, cursor);
        }
        for (long tried = 0; tried < capacity; tried++)
        {
            var code = FromIndex(cursor, length);
            cursor = (cursor + 1) % capacity;
            if (!exists(code))
                return (code, length, cursor);
        }
        throw new InvalidOperationException("Identifier allocation does not match occupied range");
    }

    /// <summary>
    /// Preserve each entity/namespace's expanded range and fallback cursor across restarts.
    /// Ordinary allocations use the map count as a cheap upper bound; only a potentially
    /// full range needs an exact count, excluding legacy IDs and other lengths.
    /// </summary>
    public static string Reserve(JsonObject state, string collection)
    {
        var records = state.Map(collection);
        var allocation = state.Map("identifier_allocation");
        var saved = allocation[collection] as JsonObject ?? new JsonObject();
        var next = Next(
            length =>
                records.Count < Capacity(length)
                    ? records.Count
                    : records.Count(pair => pair.Key.Length == length && Valid(pair.Key)),
            records.ContainsKey,
            saved.I("length", 6),
            saved["cursor"]?.GetValue<long>() ?? 0
        );
        allocation[collection] = Json.Object(("length", next.Length), ("cursor", next.Cursor));
        return next.Code;
    }

    [GeneratedRegex(@"\A(?:[A-HJ-NP-Z2-9]{10}|(?:ITM|GEN|BOX|QR)-[A-Z0-9_-]{6,48})\z")]
    private static partial Regex Legacy();
}
