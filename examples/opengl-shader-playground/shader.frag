#ifdef GL_ES
precision mediump float;
#endif

uniform float u_time;
uniform vec2 u_resolution;
uniform vec2 u_pointer;
uniform float u_intensity;
uniform float u_scale;
uniform float u_hueShift;

varying vec2 v_uv;

vec3 palette(float t)
{
    vec3 a = vec3(0.09, 0.12, 0.18);
    vec3 b = vec3(0.45, 0.28, 0.38);
    vec3 c = vec3(0.50, 0.42, 0.62);
    vec3 d = vec3(0.10, 0.20, 0.33) + vec3(u_hueShift * 0.25, u_hueShift * 0.13, -u_hueShift * 0.2);
    return a + b * cos(6.28318 * (c * t + d));
}

void main()
{
    vec2 uv = v_uv;
    vec2 fieldScale = vec2(u_resolution.x / max(u_resolution.y, 1.0), 1.0) * (1.4 + u_scale);
    vec2 p = (uv - 0.5) * fieldScale;
    vec2 pointer = (u_pointer - 0.5) * fieldScale;
    vec2 pointerDelta = p - pointer;
    float pointerDistance = length(pointerDelta);

    float t = u_time * (0.45 + u_intensity * 0.65);
    float radius = length(p);
    float ripple = sin((radius * 9.0) - (t * 3.4));
    float swirl = sin((p.x * 3.6 + t) + cos(p.y * 4.4 - t * 0.7));
    float warp = sin((p.y + pointer.x * 0.65) * 5.0 - t * 1.6) * cos((p.x - pointer.y * 0.45) * 4.0 + t * 1.2);
    float halo = exp(-5.5 * pointerDistance);
    float pointerGlow = exp(-28.0 * pointerDistance);
    float pointerRipple = sin(pointerDistance * 42.0 - t * 7.5) * exp(-12.0 * pointerDistance);

    float field = ripple * 0.32 + swirl * 0.28 + warp * 0.40 + halo * 0.75;
    vec3 color = palette(field + radius * 0.18 + t * 0.06);

    color += vec3(0.12, 0.22, 0.38) * halo * (0.8 + u_intensity * 0.45);
    color += vec3(0.85, 0.42, 0.18) * pow(max(halo, 0.0), 2.0) * 0.2;
    color += vec3(0.10, 0.95, 1.55) * max(pointerRipple, 0.0) * 0.85;
    color += vec3(1.00, 0.60, 0.22) * pointerGlow * 0.55;

    float vignette = smoothstep(1.4, 0.15, radius);
    color *= vignette;
    color = pow(max(color, vec3(0.0)), vec3(0.92));

    gl_FragColor = vec4(color, 1.0);
}
