#ifdef GL_ES
precision mediump float;
#endif

uniform vec3 u_lightDir;
uniform vec4 u_baseColor;
uniform int u_useTexture;
uniform sampler2D u_diffuseMap;

varying vec3 v_normal;
varying vec2 v_uv;

void main()
{
    vec3 normal = normalize(v_normal);
    vec3 lightDir = normalize(u_lightDir);
    float diffuse = max(dot(normal, lightDir), 0.0);
    float ambient = 0.28;

    vec4 base = u_baseColor;
    if (u_useTexture != 0) {
        base *= texture2D(u_diffuseMap, v_uv);
    }

    vec3 lit = base.rgb * (ambient + diffuse * 0.72);
    gl_FragColor = vec4(lit, base.a);
}
