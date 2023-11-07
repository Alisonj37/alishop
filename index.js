function salvou (){
    let name = document.getElementById("name").value
    let email = document.getElementById("email").value
    let age = document.getElementById("age").value
    let type = document.formulario.type.value

if (name == ""){
    alert("voce precisa informar  seu nome")
}
if (email == ""){
    alert("voce precisa informar  seu email")
}
if (age == ""){
    alert("voce precisa informar  sua idade")
}
if (type == ""){
    alert("voce precisa informar  seu typo")
}else{
    alert("voce salvou as suas informaçãoes")
}

};